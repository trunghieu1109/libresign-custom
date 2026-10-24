# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Installer regression checks; only private temporary files, no Docker/network."""
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location("libresign_deploy", Path(__file__).resolve().parents[1] / "setup.py")
setup = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(setup)


class InstallerTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="libresign-installer-test-")
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.source = self.root / "source with spaces"
        self.runtime = self.root / "private runtime"
        self.config_path = self.root / "config.json"
        for directory in (".git", "appinfo", "img", "lib/Migration", "deploy", "src"):
            (self.source / directory).mkdir(parents=True, exist_ok=True)
        (self.source / "src/main.ts").write_text("// frontend input")
        (self.source / "package-lock.json").write_text('{"lockfileVersion":3}')
        (self.source / "appinfo/info.xml").write_text('<info><dependencies><nextcloud min-version="36" max-version="36"/></dependencies></info>')
        for version in ("19002Date20261023120000", "19002Date20261023180000"):
            (self.source / f"lib/Migration/Version{version}.php").touch()
        self.config = setup.default_config(self.source)
        setup.private_json(self.config_path, self.config)
        self.calls = []
        self.provisions = []
        self.app_password = "APP-PASSWORD-MUST-NOT-APPEAR-IN-LOGS"
        self.sms_installed = False
        probe = patch.object(setup.socket, "socket")
        self.socket_factory = probe.start()
        self.addCleanup(probe.stop)

    def installer(self):
        return setup.Installer(self.runtime, self.config_path, self.source)

    def fake_run(self, args, *, data=None, capture=False, sensitive=False, env=None):
        args = [str(arg) for arg in args]
        self.calls.append((args, data))
        if "app:install" in args and "twofactor_gateway" in args:
            self.sms_installed = True
        if args[0] == "git":
            if "clone" in args:
                (Path(args[-1]) / ".git").mkdir(parents=True)
            if "rev-parse" in args:
                return setup.NCDD_REVISION + "\n"
        if "up" in args and "nextcloud" in args:
            path = self.runtime / "volumes/nextcloud/config/config.php"
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text("<?php // private test fixture")
        if "status" in args and "--output=json" in args:
            return json.dumps({"installed": True, "maintenance": False, "needsDbUpgrade": False})
        if args[-2:-1] == ["-lc"] and "npm run build" in args[-1]:
            for relative in ("vendor/autoload.php", "js/libresign-main.mjs", "css/libresign-main.css"):
                path = self.source / relative
                path.parent.mkdir(exist_ok=True)
                path.write_text("// built fixture")
        if args[-2:] == ["--services", "running"]:
            raise AssertionError("Invalid compose ps option ordering")
        if "ps" in args and "--services" in args:
            return "nextcloud\nnginx\ncron\n"
        if "provision.php" in " ".join(args):
            payload = json.loads(data)
            operation = payload["operation"]
            if operation == "sms-status":
                return json.dumps({"installed": self.sms_installed, "enabled": self.sms_installed})
            if operation == "provision":
                self.provisions.append(payload)
                credentials = dict(payload["credentials"])
                credentials["appPassword"] = credentials["appPassword"] or self.app_password
                return json.dumps({"credentials": credentials, "settings": {"uid": credentials["uid"]}})
            return json.dumps({"ok": True})
        return ""

    def install(self, **kwargs):
        installer = self.installer()
        with patch.object(installer, "preflight"), patch.object(setup, "run", side_effect=self.fake_run), contextlib.redirect_stdout(io.StringIO()) as output:
            installer.install(**kwargs)
        return output.getvalue()

    def test_fresh_install_orders_initialization_before_mount_and_starts_cron(self):
        output = self.install()
        up = [args for args, _ in self.calls if "up" in args]
        self.assertNotIn(str(self.source / "deploy/compose.source.yaml"), up[0])
        self.assertIn(str(self.source / "deploy/compose.source.yaml"), up[1])
        self.assertIn("cron", up[-1])
        self.assertTrue((self.runtime / "installed.json").exists())
        self.assertNotIn(self.app_password, output)
        self.assertIn("/index.php/apps/libresign/f/provider-management", output)
        self.assertNotIn(self.provisions[0]["credentials"]["accountPassword"], output)
        for path in [self.runtime / "admin.json", self.runtime / ".env", self.runtime / "credentials/provider-client.json",
                     self.runtime / "integration.postman_environment.private.json"]:
            self.assertEqual(0o600, path.stat().st_mode & 0o777)

    def test_rerun_preserves_passwords_tokens_and_existing_client_policy(self):
        self.install()
        admin = (self.runtime / "admin.json").read_bytes()
        credentials = (self.runtime / "credentials/provider-client.json").read_bytes()
        self.calls.clear()
        self.install()
        self.assertEqual(admin, (self.runtime / "admin.json").read_bytes())
        self.assertEqual(credentials, (self.runtime / "credentials/provider-client.json").read_bytes())
        self.assertEqual(self.app_password, self.provisions[-1]["credentials"]["appPassword"])
        self.assertFalse(self.provisions[-1]["updateClient"])
        up = [args for args, _ in self.calls if "up" in args and "nextcloud" in args]
        self.assertEqual(1, len(up))

    def test_explicit_policy_update_is_forwarded(self):
        self.install(update_client=True)
        self.assertTrue(self.provisions[0]["updateClient"])

    def test_mock_otp_policy_is_validated_and_forwarded_to_backend(self):
        self.config["integration"]["policy"]["mockOtpEnabled"] = True
        setup.private_json(self.config_path, self.config)
        self.install(update_client=True)
        self.assertIs(True, self.provisions[0]["policy"]["mockOtpEnabled"])
        self.assertEqual("email", self.provisions[0]["policy"]["otpChannel"])
        self.config["integration"]["policy"]["mockOtpEnabled"] = "true"
        setup.private_json(self.config_path, self.config)
        with self.assertRaisesRegex(setup.SetupError, "mockOtpEnabled"):
            self.installer()

    def test_defaults_and_example_use_real_email_otp(self):
        policy = self.config["integration"]["policy"]
        self.assertIs(False, policy["mockOtpEnabled"])
        example = setup.read_json(setup.DEPLOY / "config.example.json")
        self.assertEqual(policy, example["integration"]["policy"])

    def test_sms_and_dual_channel_policies_are_forwarded_without_smtp_requirement(self):
        for channel in ("sms", "email_sms"):
            with self.subTest(channel=channel):
                self.config["integration"]["policy"].update(otpChannel=channel, otpGateway=" sms ")
                setup.private_json(self.config_path, self.config)
                self.install(update_client=True)
                self.assertEqual(channel, self.provisions[-1]["policy"]["otpChannel"])
                self.assertEqual("sms", self.provisions[-1]["policy"]["otpGateway"])
                self.assertIsNone(self.provisions[-1]["smtp"])

    def test_sms_provider_configuration_can_be_deferred(self):
        for channel in ("sms", "email_sms"):
            for gateway in (None, "", "   "):
                with self.subTest(channel=channel, gateway=gateway):
                    self.config["integration"]["policy"].update(otpChannel=channel)
                    self.config["integration"]["policy"].pop("otpGateway", None)
                    if gateway is not None:
                        self.config["integration"]["policy"]["otpGateway"] = gateway
                    setup.private_json(self.config_path, self.config)
                    installer = self.installer()
                    self.assertEqual(channel, installer.policy()["otpChannel"])
        self.assertEqual([], self.calls)
        self.assertFalse(self.runtime.exists())

    def test_sms_app_is_optional_for_email(self):
        self.install()
        self.assertFalse(self.sms_installed)
        self.install(install_sms_app=True)
        self.assertTrue(self.sms_installed)
        self.assertEqual("email", self.provisions[-1]["policy"]["otpChannel"])

    def test_sms_channel_attempts_app_install_without_provider_details(self):
        self.config["integration"]["policy"]["otpChannel"] = "sms"
        setup.private_json(self.config_path, self.config)
        self.install()
        self.assertTrue(self.sms_installed)
        self.assertNotIn("otpGateway", self.provisions[-1]["policy"])

    def test_optional_sms_app_failure_does_not_block_core_installation(self):
        installer = self.installer()
        original_run = self.fake_run
        def unavailable_app(args, **kwargs):
            if "app:install" in args and "twofactor_gateway" in args:
                raise setup.SetupError("No compatible release")
            return original_run(args, **kwargs)
        with patch.object(installer, "preflight"), patch.object(setup, "run", side_effect=unavailable_app), contextlib.redirect_stdout(io.StringIO()) as output:
            installer.install(install_sms_app=True)
        self.assertTrue((self.runtime / "installed.json").exists())
        self.assertEqual("installation_pending", setup.read_json(self.runtime / "sms-app.json")["status"])
        self.assertIn("Core setup continues", output.getvalue())

    def test_enabled_sms_app_is_not_reinstalled(self):
        self.sms_installed = True
        self.install(install_sms_app=True)
        self.assertFalse(any("app:install" in args for args, _ in self.calls))

    def test_doctor_reports_pending_sms_without_failing_core_installation(self):
        installer = self.installer()
        original_run = self.fake_run
        def pending_sms(args, **kwargs):
            if "provision.php" in " ".join(map(str, args)) and json.loads(kwargs["data"])["operation"] == "http":
                return json.dumps({"ok": True, "otpChannel": "sms", "mockOtpEnabled": False,
                                   "sms": {"required": True, "ready": False, "status": "configuration_pending"}})
            return original_run(args, **kwargs)
        with patch.object(installer, "preflight"), patch.object(setup, "run", side_effect=pending_sms), contextlib.redirect_stdout(io.StringIO()) as output:
            installer.install()
        state = setup.read_json(self.runtime / "installed.json")
        self.assertFalse(state["sms"]["ready"])
        self.assertIn("SMS delivery is not ready", output.getvalue())

    def test_invalid_channel_and_gateway_are_rejected(self):
        self.config["integration"]["policy"]["otpChannel"] = "push"
        setup.private_json(self.config_path, self.config)
        with self.assertRaisesRegex(setup.SetupError, "otpChannel"):
            self.installer()
        self.config["integration"]["policy"]["otpChannel"] = "email"
        for gateway in ("sms\n", "sms\x00", "x" * 129, 123):
            with self.subTest(gateway=gateway):
                self.config["integration"]["policy"]["otpGateway"] = gateway
                setup.private_json(self.config_path, self.config)
                with self.assertRaisesRegex(setup.SetupError, "otpGateway"):
                    self.installer()

    def test_missing_mock_flag_in_old_configuration_stays_omitted_in_patch(self):
        self.config["integration"]["policy"].pop("mockOtpEnabled")
        setup.private_json(self.config_path, self.config)
        self.install(update_client=True)
        self.assertNotIn("mockOtpEnabled", self.provisions[-1]["policy"])

    def test_email_configuration_does_not_require_sms_gateway(self):
        self.install()
        self.assertNotIn("otpGateway", self.provisions[-1]["policy"])

    def test_runtime_identity_change_is_rejected_before_other_mutations(self):
        self.install()
        self.config["projectName"] = "different-project"
        setup.private_json(self.config_path, self.config)
        self.calls.clear()
        with self.assertRaisesRegex(setup.SetupError, "identity changed"):
            self.install()
        self.assertEqual([], self.calls)

    def test_unmanaged_runtime_is_never_adopted(self):
        path = self.runtime / "volumes/nextcloud/config/config.php"
        path.parent.mkdir(parents=True)
        path.write_text("original configuration")
        with self.assertRaisesRegex(setup.SetupError, "Unmanaged"):
            self.install()
        self.assertEqual("original configuration", path.read_text())

    def test_interrupted_install_resumes_saved_account_password(self):
        installer = self.installer()
        original_run = self.fake_run
        def fail_provision(args, **kwargs):
            if "provision.php" in " ".join(map(str, args)) and json.loads(kwargs["data"])["operation"] == "provision":
                raise setup.SetupError("simulated interruption")
            return original_run(args, **kwargs)
        with patch.object(installer, "preflight"), patch.object(setup, "run", side_effect=fail_provision), contextlib.redirect_stdout(io.StringIO()):
            with self.assertRaisesRegex(setup.SetupError, "interruption"):
                installer.install()
        path = self.runtime / "credentials/provider-client.json"
        saved = setup.read_json(path)
        self.assertIsNone(saved["appPassword"])
        self.assertFalse((self.runtime / "installed.json").exists())
        self.install()
        self.assertEqual(saved["accountPassword"], self.provisions[-1]["credentials"]["accountPassword"])

    def test_missing_build_artifacts_reject_skip_build(self):
        with self.assertRaisesRegex(setup.SetupError, "skip-build"):
            self.install(skip_build=True)
        self.assertFalse(self.provisions)

    def test_failed_doctor_never_marks_success(self):
        installer = self.installer()
        with patch.object(installer, "preflight"), patch.object(setup, "run", side_effect=self.fake_run), patch.object(installer, "doctor", side_effect=setup.SetupError("HTTP failed")), contextlib.redirect_stdout(io.StringIO()) as output:
            with self.assertRaisesRegex(setup.SetupError, "HTTP failed"):
                installer.install()
        self.assertFalse((self.runtime / "installed.json").exists())
        self.assertNotIn("Ready:", output.getvalue())

    def test_helper_failure_message_is_actionable(self):
        installer = self.installer()
        with patch.object(installer, "compose", return_value='{"failure":"Account already exists; supply passwordFile."}'):
            with self.assertRaisesRegex(setup.SetupError, "supply passwordFile"):
                installer.helper("provision")

    def test_private_file_permissions_are_tightened_on_replace(self):
        path = self.root / "secret.json"
        path.write_text("old")
        path.chmod(0o644)
        setup.private_json(path, {"password": "secret"})
        self.assertEqual(0o600, path.stat().st_mode & 0o777)

    def test_export_preserves_pdf_fixture_and_other_postman_variables(self):
        template = self.source / "deploy/examples/libresign-public.postman_environment.json"
        template.parent.mkdir()
        template.write_text(json.dumps({"values": [{"key": "samplePdfBase64", "value": "PDF-FIXTURE"},
                                                  {"key": "apiUser", "value": "old-user"},
                                                  {"key": "registrationOtp", "value": ""}]}))
        self.installer().export_environment({"uid": "provider-client", "appPassword": "private-token"})
        environment = setup.read_json(self.runtime / "integration.postman_environment.private.json")
        values = {item["key"]: item["value"] for item in environment["values"]}
        self.assertEqual("PDF-FIXTURE", values["samplePdfBase64"])
        self.assertEqual("provider-client", values["apiUser"])
        self.assertEqual("private-token", values["apiAppPassword"])
        self.assertEqual("", values["registrationOtp"])

    def test_runtime_under_web_served_checkout_is_rejected(self):
        with self.assertRaisesRegex(setup.SetupError, "outside the source"):
            setup.Installer(self.source / ".runtime", self.config_path, self.source)

    def test_bad_url_and_admin_integration_are_rejected(self):
        for public_url in ("https://user:password@example.com", "https://example.com/path", "http://example.com", "https://example.com:0"):
            with self.subTest(public_url=public_url):
                config = setup.default_config(self.source)
                config["publicUrl"] = public_url
                setup.private_json(self.config_path, config)
                with self.assertRaises(setup.SetupError):
                    self.installer()
        self.config["integration"]["uid"] = "admin"
        setup.private_json(self.config_path, self.config)
        with self.assertRaisesRegex(setup.SetupError, "different"):
            self.installer()

    def test_secrets_are_not_shell_evaluated_or_printed_in_plan(self):
        password = 'smtp-$(touch /tmp/never-run)-`id`-$TOKEN'
        secret = self.root / "smtp.password"
        secret.write_text(password)
        secret.chmod(0o600)
        self.config["smtp"] = {"host": "smtp.example.com", "port": 587, "secure": "tls", "auth": True,
                               "username": "test", "passwordFile": str(secret), "fromAddress": "sign", "domain": "example.com"}
        setup.private_json(self.config_path, self.config)
        self.install()
        self.assertEqual(password, self.provisions[0]["smtp"]["password"])
        self.assertTrue(all(password not in " ".join(args) for args, _ in self.calls))

    def test_shell_help_with_install_deps_does_not_run_installer(self):
        result = subprocess.run(["bash", str(setup.DEPLOY / "install.sh"), "--help", "--install-deps"], text=True, capture_output=True)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("usage:", result.stdout)

    def test_disk_failure_prevents_prepare(self):
        installer = self.installer()
        with patch.object(setup.shutil, "which", return_value="/bin/tool"), patch.object(setup.shutil, "disk_usage", return_value=type("Disk", (), {"free": 1024})()), patch.object(setup, "run", side_effect=self.preflight_run):
            with self.assertRaisesRegex(setup.SetupError, "6 GiB"):
                installer.preflight()
        self.assertFalse(self.runtime.exists())

    def test_low_space_on_separate_runtime_filesystem_prevents_installation(self):
        installer = self.installer()
        def disk_usage(path):
            free = 1024 if Path(path) == self.runtime.parent else 20 * 1024**3
            return type("Disk", (), {"free": free})()
        with patch.object(setup.shutil, "which", return_value="/bin/tool"), \
                patch.object(setup.shutil, "disk_usage", side_effect=disk_usage), \
                patch.object(setup, "run", side_effect=self.preflight_run):
            with self.assertRaisesRegex(setup.SetupError, "6 GiB"):
                installer.preflight()
        self.assertFalse(self.runtime.exists())

    def test_exported_host_values_cannot_override_saved_runtime_configuration(self):
        self.install()
        overrides = {"SOURCE_RUNTIME_DIR": "/wrong-runtime", "LIBRESIGN_SOURCE_PATH": "/wrong-source",
                     "NEXTCLOUD_ADMIN_PASSWORD": "wrong-password", "SOURCE_PUBLIC_HOST": "wrong.example",
                     "HOST_UID": "1001", "DEPLOY_HTTP_PORT": "9999", "DEPLOY_HTTPS_PORT": "9998",
                     "DEPLOY_MAILPIT_PORT": "9997", "DEPLOY_TEST_KEEP": "preserved"}
        with patch.dict(os.environ, overrides), patch.object(setup, "run", return_value="") as runner:
            self.installer().compose("config", "--quiet")
        environment = runner.call_args.kwargs.get("env")
        self.assertTrue(isinstance(environment, dict), "Compose must receive an explicit environment.")
        for key in overrides.keys() - {"DEPLOY_TEST_KEEP"}:
            self.assertTrue(key not in environment, f"Exported {key} overrides the saved runtime.")
        self.assertEqual("preserved", environment["DEPLOY_TEST_KEEP"])

    def preflight_run(self, args, **kwargs):
        return "2.24.4" if list(args[:3]) == ["docker", "compose", "version"] else "/tmp"

    def test_old_compose_is_rejected_before_runtime_mutations(self):
        with patch.object(setup.shutil, "which", return_value="/bin/tool"), patch.object(setup, "run", return_value="2.24.3"):
            with self.assertRaisesRegex(setup.SetupError, "2.24.4"):
                self.installer().preflight(disk=False)
        self.assertFalse(self.runtime.exists())

    def test_default_host_ports_are_separate_and_http_is_optional(self):
        bindings = setup.published_ports(self.config)
        self.assertEqual({18443, 18025}, {item["port"] for item in bindings})
        self.assertTrue(all(item["host"] == "127.0.0.1" for item in bindings))
        self.config["publishHttp"] = True
        bindings = setup.published_ports(self.config)
        self.assertEqual({18443, 18025, 18085}, {item["port"] for item in bindings})
        self.assertNotIn("proxy-coordinator", setup.SERVICES)

    def test_old_configuration_keeps_explicit_http_port_and_adds_new_defaults(self):
        for key in ["httpsBind", "httpsPort", "mailpitPort"]:
            self.config.pop(key)
        self.config["httpPort"] = 8085
        setup.private_json(self.config_path, self.config)
        config = self.installer().config
        self.assertEqual(8085, config["httpPort"])
        self.assertEqual(18443, config["httpsPort"])
        self.assertEqual(18025, config["mailpitPort"])

    def test_overlapping_host_ports_in_configuration_are_rejected(self):
        self.config.update(publishHttp=True, httpBind="0.0.0.0", httpPort=18443)
        setup.private_json(self.config_path, self.config)
        with self.assertRaisesRegex(setup.SetupError, "Conflicting host port"):
            self.installer()
        self.config.update(publishHttp=False, mailpitPort=18443)
        setup.private_json(self.config_path, self.config)
        with self.assertRaisesRegex(setup.SetupError, "Conflicting host port"):
            self.installer()

    def test_invalid_new_bindings_are_rejected(self):
        for key, value in [("httpsPort", 0), ("mailpitPort", 65536), ("httpsPort", True), ("httpsBind", "not-an-ip")]:
            with self.subTest(key=key):
                config = dict(self.config)
                config[key] = value
                setup.private_json(self.config_path, config)
                with self.assertRaises(setup.SetupError):
                    self.installer()

    def test_non_docker_port_conflict_prevents_container_start(self):
        self.socket_factory.return_value.__enter__.return_value.bind.side_effect = OSError("Address already in use")
        with self.assertRaisesRegex(setup.SetupError, "httpsPort"):
            self.install()
        self.assertFalse(any("up" in args or "stop" in args for args, _ in self.calls))
        self.assertFalse((self.runtime / "installed.json").exists())

    def test_docker_port_conflict_is_detected_even_without_a_host_listener(self):
        installer = self.installer()
        bindings = [{"host": "0.0.0.0", "port": 18443, "project": "another-project", "service": "nginx"}]
        with patch.object(installer, "docker_bindings", return_value=bindings):
            with self.assertRaisesRegex(setup.SetupError, "another Docker service"):
                installer.check_ports()
        self.socket_factory.assert_not_called()

    def test_port_owned_by_same_runtime_is_allowed_on_rerun(self):
        installer = self.installer()
        bindings = [{"host": "127.0.0.1", "port": 18443, "project": self.config["projectName"], "service": "nginx"}]
        with patch.object(installer, "docker_bindings", return_value=bindings):
            installer.check_ports()
        calls = self.socket_factory.return_value.__enter__.return_value.bind.call_args_list
        self.assertEqual([(("127.0.0.1", 18025),)], [call.args for call in calls])

    def test_non_overlapping_docker_bindings_do_not_block_installation(self):
        installer = self.installer()
        bindings = [{"host": "127.0.0.2", "port": 18443, "project": "another-project", "service": "nginx"},
                    {"host": "0.0.0.0", "port": 8085, "project": "another-project", "service": "nginx"}]
        with patch.object(installer, "docker_bindings", return_value=bindings):
            installer.check_ports()
        self.assertEqual(2, self.socket_factory.return_value.__enter__.return_value.bind.call_count)

    def test_docker_bindings_parse_only_published_tcp_ports(self):
        ports = {"443/tcp": [{"HostIp": "0.0.0.0", "HostPort": "18443"}],
                 "9000/tcp": None, "53/udp": [{"HostIp": "0.0.0.0", "HostPort": "53"}]}
        output = json.dumps(ports) + "\tother-project\tnginx\n"
        with patch.object(setup, "run", side_effect=["container-id\n", output]):
            bindings = self.installer().docker_bindings()
        self.assertEqual([{"host": "0.0.0.0", "port": 18443, "project": "other-project", "service": "nginx"}], bindings)

    def test_skip_build_accepts_unchanged_inputs_and_php_only_edits(self):
        self.install()
        (self.source / "lib/Backend.php").write_text("<?php // PHP-only edit")
        self.calls.clear()
        self.install(skip_build=True)
        self.assertFalse(any("npm run build" in arg for args, _ in self.calls for arg in args))

    def test_frontend_edit_rejects_skip_build_before_container_changes(self):
        self.install()
        (self.source / "src/main.ts").write_text("// changed frontend")
        self.calls.clear()
        with self.assertRaisesRegex(setup.SetupError, "without --skip-build"):
            self.install(skip_build=True)
        self.assertFalse(any("up" in args or "stop" in args for args, _ in self.calls))
        self.install()
        self.installer().check_build()

    def test_lockfile_edit_rejects_skip_build(self):
        self.install()
        (self.source / "package-lock.json").write_text('{"lockfileVersion":3,"changed":true}')
        with self.assertRaisesRegex(setup.SetupError, "without --skip-build"):
            self.install(skip_build=True)

    def test_old_runtime_needs_one_full_build_before_skip_build(self):
        self.install()
        (self.runtime / "build.json").unlink()
        with self.assertRaisesRegex(setup.SetupError, "build.json is missing"):
            self.install(skip_build=True)

    def test_missing_management_bundle_dependencies_fail_build_check(self):
        self.install()
        (self.source / "js/libresign-main.mjs").write_text("")
        with self.assertRaisesRegex(setup.SetupError, "missing or empty"):
            self.installer().check_build()

    def test_frontend_test_and_generated_asset_changes_do_not_invalidate_receipt(self):
        self.install()
        (self.source / "src/tests").mkdir()
        (self.source / "src/tests/ui.spec.ts").write_text("// test-only edit")
        (self.source / "img/generated.svg").write_text("<svg/>")
        (self.source / "js/libresign-main.mjs").write_text("// regenerated output")
        self.installer().check_build()

    def test_doctor_prints_effective_manual_dossier_policy(self):
        self.config["integration"]["policy"]["mockRegistrationEnabled"] = True
        setup.private_json(self.config_path, self.config)
        installer = self.installer()
        original_run = self.fake_run
        def effective_policy(args, **kwargs):
            result = original_run(args, **kwargs)
            if "provision.php" in " ".join(map(str, args)) and json.loads(kwargs["data"])["operation"] == "http":
                return json.dumps({"ok": True, "otpChannel": "email", "mockOtpEnabled": False, "mockRegistrationEnabled": False})
            return result
        with patch.object(installer, "preflight"), patch.object(setup, "run", side_effect=effective_policy), contextlib.redirect_stdout(io.StringIO()) as output:
            installer.install(update_client=False)
        self.assertIs(True, self.provisions[-1]["policy"]["mockRegistrationEnabled"])
        self.assertIn("Management manual dossier: disabled", output.getvalue())

    def test_failed_rebuild_invalidates_previous_receipt(self):
        self.install()
        original_run = self.fake_run
        def broken_build(args, **kwargs):
            if "npm run build" in str(args[-1]):
                raise setup.SetupError("Build failed")
            return original_run(args, **kwargs)
        installer = self.installer()
        with patch.object(installer, "preflight"), patch.object(setup, "run", side_effect=broken_build), contextlib.redirect_stdout(io.StringIO()):
            with self.assertRaisesRegex(setup.SetupError, "Build failed"):
                installer.install()
        self.assertFalse((self.runtime / "build.json").exists())

    def test_changed_inputs_during_build_are_not_marked_successful(self):
        original_run = self.fake_run
        def changing_build(args, **kwargs):
            result = original_run(args, **kwargs)
            if "npm run build" in str(args[-1]):
                (self.source / "src/main.ts").write_text("// changed during build")
            return result
        installer = self.installer()
        with patch.object(installer, "preflight"), patch.object(setup, "run", side_effect=changing_build), contextlib.redirect_stdout(io.StringIO()):
            with self.assertRaisesRegex(setup.SetupError, "changed while building"):
                installer.install()
        self.assertFalse((self.runtime / "build.json").exists())
        self.assertFalse(self.provisions)


if __name__ == "__main__":
    unittest.main()
