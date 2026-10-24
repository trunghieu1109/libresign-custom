#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Install this checkout without shell-evaluating configuration or printing secrets."""
import argparse
import fcntl
import hashlib
import ipaddress
import json
import os
from pathlib import Path
import pwd
import re
import secrets
import shutil
import subprocess
import sys
import time
from urllib.parse import urlsplit
import xml.etree.ElementTree as ET

SOURCE = Path(__file__).resolve().parent.parent
DEPLOY = SOURCE / "deploy"
NCDD_REVISION = "3d1fad2bb1bc9c880c43b68ea0845beda05c3400"
NEXTCLOUD_REVISION = "0e4914d191670dd79f9a554f3d67cbc3e0b66059"
PHP_IMAGE = "ghcr.io/librecodecoop/nextcloud-dev-php83@sha256:07428761414953a6fa57fd45cf8d4b44352c0fc36774d10b5e8ee091078915c7"
SCOPES = ["certificates:read", "certificates:write", "certificates:revoke", "signing:read", "signing:write"]
OTP_CHANNELS = ("email", "sms", "email_sms")
SERVICES = ["nextcloud", "redis", "mailpit", "nginx", "proxy-coordinator"]
DEFAULT_RUNTIME = SOURCE.parent / (".libresign-runtime-" + hashlib.sha256(str(SOURCE).encode()).hexdigest()[:8])


class SetupError(Exception):
    pass


def read_json(path):
    try:
        return json.loads(Path(path).read_text(encoding="utf-8"))
    except (OSError, ValueError) as error:
        raise SetupError(f"Cannot read valid JSON: {path}") from error


def private_json(path, value):
    """Atomic replacement with 0600 from creation, including on failed writes."""
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    temporary = path.with_name(path.name + ".tmp-" + secrets.token_hex(8))
    try:
        with open(temporary, "x", encoding="utf-8", opener=lambda p, f: os.open(p, f, 0o600)) as stream:
            json.dump(value, stream, ensure_ascii=False, indent=2)
            stream.write("\n")
        os.replace(temporary, path)
    finally:
        temporary.unlink(missing_ok=True)


def default_config(source=SOURCE):
    suffix = hashlib.sha256(str(source).encode()).hexdigest()[:8]
    project = "libresign-custom-" + suffix
    return {
        "projectName": project,
        "publicUrl": f"https://{project}.localhost",
        "publishHttp": False,
        "httpBind": "127.0.0.1",
        "httpPort": 8085,
        "adminUser": "admin",
        "integration": {
            "uid": "provider-client",
            "policy": {"enabled": True, "scopes": SCOPES, "otpChannel": "email", "mockOtpEnabled": False,
                       "trustedSources": ["provider-client"]},
        },
        "smtp": None,
        "ca": {"commonName": "LibreSign Development CA", "organization": "LibreSign Development", "country": "VN"},
    }


def secret_file(value, config_path):
    path = Path(value)
    if not path.is_absolute():
        path = config_path.parent / path
    if path.stat().st_mode & 0o077:
        raise SetupError(f"Secret file must have permission 0600: {path}")
    value = path.read_text(encoding="utf-8").rstrip("\r\n")
    if not value or "\n" in value or "\r" in value:
        raise SetupError(f"Expected a non-empty single-line secret: {path}")
    return value


def load_config(path):
    config = read_json(path)
    expected = set(default_config())
    if not isinstance(config, dict) or set(config) != expected:
        raise SetupError("Config keys must match deploy/config.example.json; regenerate with init.")
    if not re.fullmatch(r"[a-z0-9][a-z0-9_-]{0,62}", config["projectName"]):
        raise SetupError("Invalid projectName.")
    public = urlsplit(config["publicUrl"])
    try:
        port = public.port
    except ValueError as error:
        raise SetupError("Invalid publicUrl port.") from error
    if (public.scheme not in ("http", "https") or not public.hostname or public.username or public.password
            or public.path not in ("", "/") or public.query or public.fragment
            or not re.fullmatch(r"[A-Za-z0-9.-]+", public.hostname) or port == 0):
        raise SetupError("publicUrl must be http[s]://hostname[:port], without path, credentials, query or fragment.")
    config["publicUrl"] = config["publicUrl"].rstrip("/")
    if type(config["publishHttp"]) is not bool or (public.scheme == "http" and not config["publishHttp"]):
        raise SetupError("HTTP publicUrl requires publishHttp=true.")
    try:
        ipaddress.IPv4Address(config["httpBind"])
    except ValueError as error:
        raise SetupError("httpBind must be an IPv4 bind address.") from error
    if type(config["httpPort"]) is not int or not 1 <= config["httpPort"] <= 65535:
        raise SetupError("httpPort must be between 1 and 65535.")
    integration = config["integration"]
    if not isinstance(integration, dict) or not set(integration) <= {"uid", "policy", "passwordFile", "appPasswordFile"}:
        raise SetupError("Invalid integration configuration.")
    for uid in (config["adminUser"], integration.get("uid", "")):
        if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.@-]{0,63}", uid):
            raise SetupError("Account UID must be 1-64 letters, digits, underscore, dot, @ or hyphen.")
    if integration["uid"].lower() == config["adminUser"].lower():
        raise SetupError("Integration account must be different from the administrator.")
    policy = integration.get("policy")
    allowed = {"enabled", "scopes", "otpChannel", "otpGateway", "trustedSources", "assertionPublicKeyFile", "callbackHosts", "callbackSecretFile",
               "edgeDeployment", "mockRegistrationEnabled", "mockOtpEnabled", "requireReuseFaceMatch", "reuseFaceThreshold", "reuseFaceScale", "registrationBaseUrl"}
    if not isinstance(policy, dict) or set(policy) - allowed:
        raise SetupError("Unknown integration.policy key.")
    if "scopes" in policy and (not isinstance(policy["scopes"], list) or not policy["scopes"] or any(s not in SCOPES for s in policy["scopes"])):
        raise SetupError("Invalid integration scopes.")
    for key in ("enabled", "edgeDeployment", "mockRegistrationEnabled", "mockOtpEnabled", "requireReuseFaceMatch"):
        if key in policy and type(policy[key]) is not bool:
            raise SetupError(f"{key} must be a JSON boolean.")
    channel = policy.get("otpChannel", "email")
    if channel not in OTP_CHANNELS:
        raise SetupError("otpChannel must be email, sms or email_sms.")
    gateway = policy.get("otpGateway", "")
    if not isinstance(gateway, str) or len(gateway) > 128 or re.search(r"[\x00-\x1f\x7f]", gateway):
        raise SetupError("otpGateway must be a gateway name of at most 128 characters, without control characters.")
    if "otpGateway" in policy:
        policy["otpGateway"] = gateway.strip()
    for key in ("trustedSources", "callbackHosts"):
        if key in policy and (not isinstance(policy[key], list) or any(not isinstance(s, str) or not s or len(s) > 255 for s in policy[key])):
            raise SetupError(f"{key} must contain non-empty strings.")
    if "callbackHosts" in policy and any(not re.fullmatch(r"[a-z0-9.-]+", s) for s in policy["callbackHosts"]):
        raise SetupError("callbackHosts must be lowercase hostnames, without scheme, port or path.")
    if "registrationBaseUrl" in policy and policy["registrationBaseUrl"]:
        registration = urlsplit(policy["registrationBaseUrl"])
        if registration.scheme != "https" or not registration.hostname or registration.username or registration.password or registration.fragment:
            raise SetupError("registrationBaseUrl must be an HTTPS URL without credentials or a fragment.")
    if any(k in policy for k in ("reuseFaceThreshold", "reuseFaceScale")) or policy.get("requireReuseFaceMatch"):
        threshold, scale = policy.get("reuseFaceThreshold"), policy.get("reuseFaceScale")
        if (type(threshold) not in (int, float) or type(scale) not in (int, float)
                or not 0 < threshold <= scale or not scale < float("inf")):
            raise SetupError("Set finite 0 < reuseFaceThreshold <= reuseFaceScale.")
    ca = config["ca"]
    if not isinstance(ca, dict) or set(ca) != {"commonName", "organization", "country"}:
        raise SetupError("Invalid CA configuration.")
    if any(not isinstance(v, str) or not v or len(v) > 128 or re.search(r"[\x00-\x1f]", v) for v in ca.values()):
        raise SetupError("Invalid CA subject.")
    if not re.fullmatch(r"[A-Z]{2}", ca["country"]):
        raise SetupError("CA country must have two uppercase letters.")
    smtp = config["smtp"]
    if smtp is not None:
        if not isinstance(smtp, dict) or set(smtp) != {"host", "port", "secure", "auth", "username", "passwordFile", "fromAddress", "domain"}:
            raise SetupError("Invalid SMTP keys; see config.example.json and README.")
        if smtp["secure"] not in ("", "tls", "ssl") or type(smtp["auth"]) is not bool:
            raise SetupError("SMTP secure must be tls, ssl or empty; auth must be boolean.")
        if type(smtp["port"]) is not int or not 1 <= smtp["port"] <= 65535:
            raise SetupError("Invalid SMTP port.")
        for key in ("host", "username", "fromAddress", "domain"):
            if not isinstance(smtp[key], str) or re.search(r"[\x00-\x1f]", smtp[key]) or (key != "username" and not smtp[key]):
                raise SetupError(f"Invalid SMTP {key}.")
        if "@" in smtp["fromAddress"]:
            raise SetupError("SMTP fromAddress is the local part before @.")
        if smtp["auth"] and (not smtp["username"] or not smtp["passwordFile"]):
            raise SetupError("SMTP authentication requires username and passwordFile.")
    return config


def run(args, *, data=None, capture=False, sensitive=False, env=None):
    result = subprocess.run([str(a) for a in args], input=data, text=True,
                            stdout=subprocess.PIPE if capture or sensitive else None,
                            stderr=subprocess.PIPE if sensitive else None, env=env, check=False)
    if result.returncode:
        # Input/output can contain passwords. Never reproduce sensitive helper output.
        raise SetupError(f"Command failed ({result.returncode}): {Path(str(args[0])).name}" + ("; sensitive output withheld." if sensitive else "."))
    return result.stdout or ""


class Installer:
    def __init__(self, runtime, config_path, source=SOURCE):
        self.source = source.resolve()
        self.deploy = self.source / "deploy"
        self.runtime = Path(runtime).resolve()
        if self.runtime.is_relative_to(self.source):
            raise SetupError("Runtime must be outside the source checkout, so credentials are outside the mounted application/web root.")
        self.config_path = Path(config_path).resolve()
        self.config = load_config(self.config_path)
        self.ncdd = self.runtime / "ncdd"

    def compose(self, *args, attached=True, **kwargs):
        command = ["docker", "compose", "--project-directory", self.ncdd, "--env-file", self.runtime / ".env",
                   "--project-name", self.config["projectName"], "-f", self.ncdd / "docker-compose.yml", "-f", self.deploy / "compose.yaml"]
        if attached:
            command += ["-f", self.deploy / "compose.source.yaml"]
            if self.config["publishHttp"]:
                command += ["-f", self.deploy / "compose.http.yaml"]
        # Compose gives exported shell variables precedence over --env-file.
        # Keep this runtime's saved settings authoritative while retaining PATH,
        # Docker connection options and unrelated host environment variables.
        environment = os.environ.copy()
        for key in re.findall(r"^([A-Z][A-Z0-9_]*)=", (self.runtime / ".env").read_text(encoding="utf-8"), re.MULTILINE):
            environment.pop(key, None)
        return run(command + list(args), env=environment, **kwargs)

    def occ(self, *args, **kwargs):
        return self.compose("exec", "-T", "--user", "www-data", "nextcloud", "php", "occ", *args, **kwargs)

    def source_git(self, *args):
        command = ["git", "-C", self.source, *args]
        owner = self.source.stat().st_uid
        if os.geteuid() == 0 and owner != 0:
            # A normal-user clone followed by sudo install must not make root own
            # its Git metadata or require persistent global safe.directory changes.
            command = ["runuser", "-u", pwd.getpwuid(owner).pw_name, "--", *command]
        return run(command)

    def helper(self, operation, **values):
        result = self.compose("exec", "-T", "--user", "www-data", "nextcloud", "php", "/opt/libresign-deploy/provision.php",
                              data=json.dumps({"operation": operation, **values}), capture=True, sensitive=True)
        try:
            result = json.loads(result)
        except ValueError as error:
            raise SetupError("Provisioning helper did not return JSON; inspect runtime logs without exposing credentials.") from error
        if "failure" in result:
            raise SetupError(result["failure"])
        return result

    def check_source(self):
        manifest = ET.parse(self.source / "appinfo/info.xml").getroot()
        dependency = manifest.find("dependencies/nextcloud")
        if dependency is None or dependency.get("min-version") != "36" or dependency.get("max-version") != "36":
            raise SetupError("This pinned installer requires the Nextcloud 36 fork; review runtime pins for this checkout.")
        if not (self.source / ".git").exists():
            raise SetupError("Clone the source repository with Git, including submodules; a copied source archive is insufficient.")

    def preflight(self, disk=True):
        self.check_source()
        for tool in ("docker", "git", "setfacl"):
            if shutil.which(tool) is None:
                raise SetupError(f"Missing {tool}; on Ubuntu use install --install-deps.")
        run(["docker", "compose", "version"], capture=True)
        run(["docker", "info"], capture=True, sensitive=True)
        if disk:
            # Source, runtime data and Docker images can live on separate disks.
            # A fresh runtime may not exist yet; inspect its nearest existing parent.
            docker_root = run(["docker", "info", "--format", "{{.DockerRootDir}}"], capture=True).strip()
            runtime_parent = self.runtime
            while not runtime_parent.exists():
                runtime_parent = runtime_parent.parent
            for path in (self.source, runtime_parent, Path(docker_root)):
                if path.exists() and shutil.disk_usage(path).free < 6 * 1024**3:
                    raise SetupError(f"Less than 6 GiB free at {path}; provide more disk space before installation.")

    def prepare(self):
        config = self.config
        self.runtime.mkdir(parents=True, exist_ok=True, mode=0o700)
        identity = self.runtime / "identity.json"
        wanted = {"source": str(self.source), "runtime": str(self.runtime), "projectName": config["projectName"], "adminUser": config["adminUser"]}
        if identity.exists() and read_json(identity) != wanted:
            raise SetupError("Runtime identity changed. Keep source path, projectName and adminUser; migrate explicitly.")
        if not identity.exists() and (self.runtime / "volumes/nextcloud/config/config.php").exists():
            raise SetupError("Unmanaged Nextcloud data exists here. Choose a new runtime directory; do not overwrite it.")
        if not (self.ncdd / ".git").exists():
            run(["git", "clone", "--filter=blob:none", "--no-checkout", "https://github.com/LibreCodeCoop/nextcloud-docker-development.git", self.ncdd])
            run(["git", "-C", self.ncdd, "checkout", "--detach", NCDD_REVISION])
        revision = run(["git", "-C", self.ncdd, "rev-parse", "HEAD"], capture=True).strip()
        if revision != NCDD_REVISION:
            raise SetupError("Unexpected NCDD revision. No checkout or reset was performed.")
        self.source_git("submodule", "update", "--init", "--recursive")
        env_path = self.runtime / ".env"
        # A dedicated private JSON record allows preserving the admin password without parsing shell syntax.
        admin_path = self.runtime / "admin.json"
        if admin_path.exists():
            admin = read_json(admin_path)
            if admin.get("uid") != config["adminUser"]:
                raise SetupError("Administrator UID differs from saved credentials.")
        else:
            if env_path.exists():
                raise SetupError("Runtime .env exists without saved admin credentials; refusing to replace passwords.")
            admin = {"uid": config["adminUser"], "password": secrets.token_urlsafe(36)}
            private_json(admin_path, admin)
        public = urlsplit(config["publicUrl"])
        values = {
            "COMPOSE_PROJECT_NAME": config["projectName"], "SOURCE_RUNTIME_DIR": str(self.runtime),
            "LIBRESIGN_SOURCE_PATH": str(self.source), "WORKER_VOLUMES_DIR": str(self.runtime / "volumes"),
            "SOURCE_PHP_IMAGE": PHP_IMAGE, "HOST_UID": "33", "HOST_GID": "33", "DB_TYPE": "sqlite", "DB_DRIVER": "sqlite",
            "DB_HOST": "sqlite", "DB_SERVICE_TYPE": "mysql", "VERSION_NEXTCLOUD": NEXTCLOUD_REVISION,
            "NEXTCLOUD_HOST": public.hostname, "SOURCE_PUBLIC_HOST": public.netloc, "SOURCE_PROTOCOL": public.scheme,
            "NEXTCLOUD_PROTOCOL": public.scheme, "NEXTCLOUD_ADMIN_USER": admin["uid"], "NEXTCLOUD_ADMIN_PASSWORD": admin["password"],
            "DEFAULT_PHONE_REGION": "VN", "PROXY_IP_BIND": "127.0.0.1", "DEPLOY_HTTP_BIND": config["httpBind"], "DEPLOY_HTTP_PORT": str(config["httpPort"]),
        }
        # Compose supports literal single-quoted values, including escaped single quotes.
        encoded = "".join(key + "='" + value.replace("'", "\\'") + "'\n" for key, value in values.items())
        temporary = env_path.with_name(".env.tmp")
        temporary.unlink(missing_ok=True)
        with open(temporary, "x", opener=lambda p, f: os.open(p, f, 0o600)) as stream:
            stream.write(encoded)
        os.replace(temporary, env_path)
        private_json(identity, wanted)
        for relative in ("cache/composer", "cache/npm", "volumes/nextcloud", "volumes/php"):
            path = self.runtime / relative
            path.mkdir(parents=True, exist_ok=True)
            run(["setfacl", "-m", "u:33:rwx,d:u:33:rwx", path])
        # Do not recursively chown tracked source. UID 33 needs writable build directories.
        run(["setfacl", "-m", "u:33:rwx", self.source, self.source / "appinfo"])
        run(["setfacl", "-R", "-m", "u:33:rwX", self.source / "img"])
        for relative in ("vendor", "node_modules", "js", "css", "dist"):
            path = self.source / relative
            path.mkdir(exist_ok=True)
            run(["setfacl", "-R", "-m", "u:33:rwX,d:u:33:rwx", path])
        self.compose("config", "--quiet")

    def wait_ready(self, attached):
        for _ in range(180):
            try:
                self.compose("exec", "-T", "--user", "www-data", "nextcloud", "php", "-r",
                             '$socket = @fsockopen("127.0.0.1", 9000); exit($socket ? 0 : 1);',
                             attached=attached, capture=True, sensitive=True)
                output = self.compose("exec", "-T", "--user", "www-data", "nextcloud", "php", "occ", "status", "--output=json",
                                      attached=attached, capture=True, sensitive=True)
                state = json.loads(output)
                if state.get("installed") and not state.get("maintenance") and not state.get("needsDbUpgrade"):
                    return
            except (SetupError, ValueError):
                pass
            time.sleep(2)
        raise SetupError("Nextcloud did not become ready. Use deploy/runtime.sh logs --tail=100 nextcloud nginx.")

    def credentials(self):
        integration = self.config["integration"]
        path = self.runtime / "credentials" / (integration["uid"] + ".json")
        if path.exists():
            credentials = read_json(path)
            if credentials.get("uid") != integration["uid"]:
                raise SetupError("Saved credential UID mismatch.")
        else:
            credentials = {"uid": integration["uid"], "accountPassword": secrets.token_urlsafe(36), "appPassword": None}
        for setting, key in (("passwordFile", "accountPassword"), ("appPasswordFile", "appPassword")):
            if integration.get(setting):
                credentials[key] = secret_file(integration[setting], self.config_path)
        # Persist before making a user/token so interrupted installation can resume.
        private_json(path, credentials)
        return path, credentials

    def policy(self):
        policy = dict(self.config["integration"]["policy"])
        if "callbackSecretFile" in policy:
            policy["callbackSecret"] = secret_file(policy.pop("callbackSecretFile"), self.config_path)
            if len(policy["callbackSecret"]) < 32:
                raise SetupError("callbackSecret must contain at least 32 characters.")
        if "assertionPublicKeyFile" in policy:
            path = Path(policy.pop("assertionPublicKeyFile"))
            if not path.is_absolute():
                path = self.config_path.parent / path
            policy["assertionPublicKey"] = path.read_text(encoding="utf-8")
        return policy

    def smtp(self):
        smtp = self.config["smtp"]
        if smtp is None:
            return None
        smtp = dict(smtp)
        password_file = smtp.pop("passwordFile")
        smtp["password"] = secret_file(password_file, self.config_path) if password_file else ""
        return smtp

    def install_sms_app(self):
        """Optional dependency only; provider settings are deliberately left for later."""
        try:
            status = self.helper("sms-status")
            if not status.get("installed"):
                self.occ("app:install", "twofactor_gateway", capture=True, sensitive=True)
            elif not status.get("enabled"):
                self.occ("app:enable", "twofactor_gateway", capture=True, sensitive=True)
            status = self.helper("sms-status")
            print("SMS app: " + ("installed/enabled; provider configuration can be added later."
                                 if status.get("enabled") else "pending; core setup continues."))
        except SetupError:
            # Nextcloud 36 dev may not have a compatible App Store release.
            # Do not force an incompatible version or fail the email/API setup.
            status = {"status": "installation_pending"}
            try:
                status.update(self.helper("sms-status"))
            except SetupError:
                pass
            print("SMS app installation is pending (download/compatibility/enablement). Core setup continues; retry app:install twofactor_gateway when needed.")
        private_json(self.runtime / "sms-app.json", status)

    def install(self, skip_build=False, update_client=False, install_sms_app=False):
        self.preflight()
        # Validate referenced files before downloading or mutating runtime data.
        policy, smtp = self.policy(), self.smtp()
        integration = self.config["integration"]
        for setting in ("passwordFile", "appPasswordFile"):
            if integration.get(setting):
                secret_file(integration[setting], self.config_path)
        print("[1/7] Preparing pinned runtime and preserving credentials.", flush=True)
        self.prepare()
        config_file = self.runtime / "volumes/nextcloud/config/config.php"
        if not config_file.exists():
            print("[2/7] Initializing Nextcloud before attaching app source.", flush=True)
            self.compose("up", "-d", *SERVICES, attached=False)
            self.wait_ready(attached=False)
        print("[3/7] Attaching source and building dependencies/assets.", flush=True)
        self.compose("up", "-d", *SERVICES)
        self.wait_ready(attached=True)
        self.compose("stop", "cron")
        if not skip_build:
            self.compose("exec", "-T", "--user", "www-data", "nextcloud", "bash", "-lc",
                         'set -e; cd /var/www/html/apps-extra/libresign; '
                         'git config --global --get-all safe.directory | grep -Fxq "$PWD" || git config --global --add safe.directory "$PWD"; '
                         'composer install --no-dev --no-scripts --no-interaction --prefer-dist --no-progress; '
                         'npm ci --no-audit --no-fund; npm run build')
        else:
            for relative in ("vendor/autoload.php", "js", "css"):
                path = self.source / relative
                if not path.exists() or (path.is_dir() and not any(path.iterdir())):
                    raise SetupError(f"--skip-build requested but {relative} is missing or empty.")
        print("[4/7] Enabling app, applying provider migrations and installing signing tools.", flush=True)
        self.occ("app:enable", "libresign")
        # Custom migration IDs exceed the upstream app major. Apply only pending IDs;
        # migrations:execute rejects previously executed steps when debug is disabled.
        versions = [m.stem.removeprefix("Version") for m in sorted((self.source / "lib/Migration").glob("Version19002Date*.php"))]
        if not versions:
            raise SetupError("Custom provider migrations are missing from this checkout.")
        self.helper("migrate", versions=versions)
        self.occ("libresign:install", "--use-local-cert", "--java", "--jsignpdf", "--pdftk")
        self.helper("ca", ca=self.config["ca"])
        self.occ("config:system:set", "overwrite.cli.url", "--value=" + self.config["publicUrl"])
        self.occ("config:system:set", "defaultapp", "--value=libresign")
        self.occ("background:cron")
        if install_sms_app or policy.get("otpChannel") in ("sms", "email_sms"):
            self.install_sms_app()
        print("[5/7] Provisioning integration account and scoped policy.", flush=True)
        credential_path, credentials = self.credentials()
        result = self.helper("provision", credentials=credentials, policy=policy, smtp=smtp, updateClient=update_client)
        credentials.update(result["credentials"])
        private_json(credential_path, credentials)
        private_json(self.runtime / "client-settings.json", result["settings"])
        self.export_environment(credentials)
        print("[6/7] Starting periodic background jobs.", flush=True)
        self.compose("up", "-d", "cron")
        print("[7/7] Checking CA/tools, database, jobs and HTTP API authentication.", flush=True)
        checks = self.doctor(credentials)
        private_json(self.runtime / "installed.json", {"publicUrl": self.config["publicUrl"], "uid": credentials["uid"],
                                                       "sms": (checks or {}).get("sms")})
        print(f"Ready: {self.config['publicUrl']}\nAdmin credentials: {self.runtime / 'admin.json'}\nIntegration credentials: {credential_path}\nPrivate Postman environment: {self.runtime / 'integration.postman_environment.private.json'}")

    def export_environment(self, credentials):
        values = {"baseUrl": self.config["publicUrl"] + "/index.php/apps/libresign/api/v1/public",
                  "apiUser": credentials["uid"], "apiAppPassword": credentials["appPassword"]}
        template = self.deploy / "examples/libresign-public.postman_environment.json"
        environment = read_json(template) if template.exists() else {"values": []}
        environment["name"] = self.config["projectName"] + " — PRIVATE"
        environment["_postman_variable_scope"] = "environment"
        existing = {item["key"]: item for item in environment["values"]}
        for key, value in values.items():
            existing[key] = {"key": key, "value": value, "type": "secret" if key == "apiAppPassword" else "default", "enabled": True}
        environment["values"] = list(existing.values())
        private_json(self.runtime / "integration.postman_environment.private.json", environment)

    def doctor(self, credentials=None):
        if not (self.runtime / ".env").exists():
            raise SetupError("Runtime has not been installed.")
        result = self.helper("check")
        if not result.get("ok"):
            raise SetupError("Signing validation failed: " + ", ".join(result.get("errors", [])))
        running = self.compose("ps", "--status", "running", "--services", capture=True).splitlines()
        if "cron" not in running:
            raise SetupError("Background cron service is not running.")
        if credentials is None:
            credentials = read_json(self.runtime / "credentials" / (self.config["integration"]["uid"] + ".json"))
        # HTTP requests run inside the container and traverse nginx/PHP-FPM with the canonical Host.
        # No TLS verification bypass or public network exposure is needed for this internal check.
        response = self.helper("http", uid=credentials["uid"], appPassword=credentials["appPassword"],
                               host=urlsplit(self.config["publicUrl"]).netloc)
        if not response.get("ok"):
            raise SetupError("HTTP API smoke checks failed.")
        print("PASS: signing tools/CA, provider tables/job, cron, authenticated API and anonymous rejection.")
        print("OTP channel: " + str(response.get("otpChannel")) + "; mock OTP: "
              + ("enabled (integration testing)" if response.get("mockOtpEnabled") else "disabled"))
        if response.get("sms", {}).get("required"):
            print("SMS: " + ("gateway configured; delivery still needs a real recipient test."
                              if response["sms"].get("ready") else "provider configuration pending; SMS delivery is not ready."))
        return response


def parser():
    root = argparse.ArgumentParser(description="Install this LibreSign 16 / Nextcloud 36 development fork. Ubuntu/Linux; no production certification.")
    root.add_argument("command", choices=("init", "plan", "install", "doctor", "runtime"))
    root.add_argument("--runtime-dir", type=Path, default=DEFAULT_RUNTIME)
    root.add_argument("--config", type=Path)
    root.add_argument("--skip-build", action="store_true", help="Reuse already built dependencies and frontend")
    root.add_argument("--update-client", action="store_true", help="Merge explicitly configured policy keys into an existing client")
    root.add_argument("--install-sms-app", action="store_true", help="Try installing/enabling Two-Factor Gateway; leave SMS provider configuration for later")
    return root


def main(argv=None):
    # Runtime forwards its remaining args verbatim; options precede its Compose command.
    argv = list(sys.argv[1:] if argv is None else argv)
    runtime_args = []
    if argv and argv[0] == "runtime":
        index = 1
        while index < len(argv) and argv[index] in ("--runtime-dir", "--config"):
            index += 2
        runtime_args, argv = argv[index:], argv[:index]
    args = parser().parse_args(argv)
    runtime = args.runtime_dir.resolve()
    config_path = (args.config or runtime / "install.json").resolve()
    if args.command == "init":
        if config_path.exists():
            print(f"Keeping existing configuration: {config_path}")
        else:
            private_json(config_path, default_config())
            print(f"Created {config_path}; review publicUrl, integration and SMTP before install.")
        return 0
    if args.command == "install" and not config_path.exists():
        private_json(config_path, default_config())
        print(f"Created default local configuration: {config_path}")
    installer = Installer(runtime, config_path)
    if args.command == "plan":
        installer.check_source()
        installer.policy()
        installer.smtp()
        print(json.dumps({"source": str(SOURCE), "runtime": str(runtime), "projectName": installer.config["projectName"],
                          "publicUrl": installer.config["publicUrl"], "integrationUid": installer.config["integration"]["uid"],
                          "requestedOtpPolicy": {k: installer.config["integration"]["policy"][k] for k in ("otpChannel", "otpGateway", "mockOtpEnabled")
                                                 if k in installer.config["integration"]["policy"]},
                          "existingPolicy": "merge explicit keys" if args.update_client else "preserve; use --update-client to apply policy edits",
                          "smsApp": "try install/enable; provider setup deferred" if args.install_sms_app or installer.config["integration"]["policy"].get("otpChannel") in ("sms", "email_sms") else "optional; not requested",
                          "steps": ["check host dependencies/disk", "prepare pinned NCDD/Nextcloud", "build source", "enable/migrate/install tools and CA", "provision account/App Password", "start cron", "HTTP checks"],
                          "smtp": "preserve existing; new runtime uses Mailpit" if installer.config["smtp"] is None else "apply explicit SMTP configuration"}, indent=2))
        return 0
    if args.command == "runtime":
        if not runtime_args:
            raise SetupError("Usage: runtime.sh [--runtime-dir DIR] [--config FILE] ps|logs|up|stop|occ|exec ...")
        command, *remaining = runtime_args
        if command == "occ":
            installer.occ(*remaining)
        elif command == "exec":
            installer.compose("exec", "-T", "--user", "www-data", "nextcloud", *remaining)
        elif command == "up":
            installer.compose("up", "-d", *remaining, *SERVICES, "cron")
        elif command in ("ps", "logs", "config", "stop", "start", "restart"):
            installer.compose(command, *remaining)
        else:
            raise SetupError("Unsupported runtime command; data removal is intentionally not part of this helper.")
        return 0
    # Only the installer takes an exclusive lock. Doctor performs read-only checks.
    if args.command == "doctor":
        installer.preflight(disk=False)
        installer.doctor()
        return 0
    runtime.mkdir(parents=True, exist_ok=True, mode=0o700)
    with (runtime / "install.lock").open("a") as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError as error:
            raise SetupError("Another installer is running for this runtime.") from error
        installer.install(args.skip_build, args.update_client, args.install_sms_app)
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except (SetupError, OSError, ValueError, TypeError, KeyError, ET.ParseError) as error:
        # Errors from helpers/HTTP are deliberately sanitized at their boundary.
        print(f"ERROR: {error}", file=sys.stderr)
        sys.exit(1)
