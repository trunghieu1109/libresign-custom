#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Export the partner contract without changing Nextcloud's generated specifications.

The extractor cannot infer service-level authentication, request headers, or
fields read from raw JSON. Keep those documentation overrides here. Run after
composer openapi; --check verifies the export and route/Postman inventories.
"""
import argparse
import copy
import json
from pathlib import Path
import re
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
PREFIX = "/index.php/apps/libresign/api/v1/public"
OUTPUT = ROOT / "deploy/examples/libresign-public.openapi.json"


def string(**kwargs):
    return {"type": "string", **kwargs}


def obj(properties, required=(), **kwargs):
    result = {"type": "object", "properties": properties, **kwargs}
    if required:
        result["required"] = list(required)
    return result


def ref(name):
    return {"$ref": f"#/components/schemas/{name}"}


def array(items, **kwargs):
    return {"type": "array", "items": items, **kwargs}


def envelope(data, listing=False):
    fields = {"status": {"type": "boolean", "enum": [True]}, "timestamp": string(format="date-time"),
              "transId": string(), "data": data}
    if listing:
        fields["totalItem"] = {"type": "integer", "minimum": 0}
    return obj(fields, fields)


def export():
    source = json.loads((ROOT / "openapi.json").read_text())
    controller = (ROOT / "lib/Controller/PublicProviderController.php").read_text()
    matches = list(re.finditer(r"#\[FrontpageRoute\(verb: '(\w+)', url: '(/api/v1/public[^']*)'\)\]", controller))
    scopes = {}
    for index, match in enumerate(matches):
        end = matches[index + 1].start() if index + 1 < len(matches) else len(controller)
        scope = re.search(r"\$this->call\('([^']+)'", controller[match.end():end])
        if scope is None:
            raise ValueError(f"No scope found for {match.group(2)}")
        scopes[(match.group(1).lower(), "/index.php/apps/libresign" + match.group(2))] = scope.group(1)
    paths = {path: copy.deepcopy(item) for path, item in source["paths"].items() if path.startswith(PREFIX + "/")}
    inventory = {(method, path) for path, item in paths.items() for method in item}
    if inventory != set(scopes):
        raise ValueError("Public routes and openapi.json differ; regenerate with composer openapi first")
    reference = (ROOT / "deploy/API_REFERENCE.md").read_text()
    documented = {(method.lower(), PREFIX + path)
                  for method, path in re.findall(r"^\| (GET|POST) \| `([^`]+)`", reference, re.MULTILINE)}
    if documented != inventory:
        raise ValueError("API_REFERENCE.md and public routes differ; update the endpoint reference")

    schemas = copy.deepcopy(source["components"]["schemas"])
    personal_id = string(pattern="^[0-9]{12}$", description="String; preserve leading zeroes.")
    otp = string(pattern="^[0-9]{6}$")
    binary = string(format="byte", minLength=1, description="Plain Base64, without a data URI prefix.")
    confirmation = string(enum=["service_otp", "external_assertion"], default="service_otp")
    receipt = obj({"method": string(maxLength=64), "source": string(maxLength=64),
                   "reference": string(maxLength=256), "verifiedAt": string(format="date-time", description="RFC3339 without fractional seconds.")})
    appearance = {"type": string(enum=["PDF"], default="PDF"),
                  "signingProfileCode": string(enum=["DEFAULT_PDF"], default="DEFAULT_PDF"),
                  "documentName": string(maxLength=255, default="document.pdf"),
                  "signerName": string(maxLength=128), "reason": string(maxLength=512),
                  "page": {"type": "integer", "minimum": -1, "description": "1-based; 0 or -1 selects the last page. Default 1 with a rectangle."},
                  "signatureSearchText": string(maxLength=256, description="One matching text segment across the PDF; no OCR. Alternative to coordinates."),
                  **{name: {"type": "number"} for name in ("llx", "lly", "urx", "ury")},
                  **{name: {**binary, "description": "PNG, at most 1 MiB decoded and 4 million pixels; requires a visible rectangle."}
                     for name in ("signatureImageBase64", "stampBase64", "logoBase64")}}
    aliases = {"pdfBase64": "fileBase64", "signaturePage": "page", "signatureLlx": "llx", "signatureLly": "lly",
               "signatureUrx": "urx", "signatureUry": "ury", "sealImageBase64": "stampBase64"}
    file_fields = {**appearance, "id": string(maxLength=128, description="Unique within the batch; defaults to the zero-based index string."),
                   "fileBase64": {**binary, "description": "PDF, at most 5 MiB decoded."}}
    for alias, canonical in aliases.items():
        file_fields[alias] = {**file_fields[canonical], "description": f"Alias of {canonical}; values must agree when both are supplied."}
    schemas["SigningFileInput"] = obj(file_fields, anyOf=[{"required": ["fileBase64"]}, {"required": ["pdfBase64"]}])
    start_fields = {**file_fields, "certAlias": string(maxLength=36), "personalIdNumber": personal_id,
                    "confirmationMethod": confirmation, "callbackUrl": string(nullable=True, description="Optional allowlisted HTTPS URL.")}
    start_fields["files"] = array(ref("SigningFileInput"), minItems=1, maxItems=100,
                                 description="Per-file appearance overrides the top-level values; do not also send a top-level PDF.")
    schemas["SigningStartInput"] = obj(start_fields, allOf=[
        {"anyOf": [{"required": ["certAlias"]}, {"required": ["personalIdNumber"]}]},
        {"oneOf": [{"required": ["files"], "not": {"anyOf": [{"required": ["fileBase64"]}, {"required": ["pdfBase64"]}]}},
                   {"anyOf": [{"required": ["fileBase64"]}, {"required": ["pdfBase64"]}], "not": {"required": ["files"]}}]},
    ])
    # Do not allow a confirmation ID to be interpreted as a new signing request.
    schemas["SigningStartInput"]["not"] = {"required": ["signSessionId"]}
    schemas["SigningConfirmInput"] = obj({"signSessionId": string(maxLength=36), "otpValue": otp, "assertion": binary,
                                          "certAlias": string(maxLength=36), "personalIdNumber": personal_id},
                                         ["signSessionId"], additionalProperties=False,
                                         anyOf=[{"required": ["otpValue"]}, {"required": ["assertion"]}])
    result_fields = {"id": string(), "index": {"type": "integer"}, "documentName": string(), "type": string(enum=["PDF"]),
                     "status": string(enum=["PENDING", "SIGNING", "SUCCESS", "FAILED", "RECOVERY_REQUIRED"]),
                     "signedData": string(format="byte", nullable=True), "outputHash": string(description="SHA-256 hex of decoded signed PDF."),
                     "errorCode": string(), "errorMessage": string()}
    schemas["SigningDocumentResult"] = obj(result_fields, ["id", "index", "documentName", "type", "status", "signedData"])
    signing_fields = {"id": string(), "transactionId": string(), "signSessionId": string(), "status": string(),
                      "personalIdNumber": personal_id, "certAlias": string(), "signerName": string(),
                      "documentCount": {"type": "integer"}, "otpRequired": {"type": "boolean"},
                      "otpChannel": string(nullable=True), "email": string(), "phone": string(), "expiresAt": string(format="date-time"),
                      "results": array(ref("SigningDocumentResult")), "successCount": {"type": "integer"}, "failedCount": {"type": "integer"}}
    schemas["SigningSession"] = obj({**signing_fields, "challengeBase64": binary, "documentName": string(),
                                      "type": string(enum=["PDF"]), "signedData": string(format="byte", nullable=True)}, signing_fields)
    schemas["ErrorEnvelope"] = obj({"status": {"type": "boolean", "enum": [False]}, "timestamp": string(format="date-time"),
                                    "transId": string(), "data": {"type": "object", "nullable": True, "enum": [None]},
                                    "error": obj({"code": string(), "message": string()}, ["code", "message"])},
                                   ["status", "timestamp", "transId", "data", "error"])
    package_fields = {"caVendor": string(), "code": string(), "name": string(), "description": string(),
                      "validityDays": {"type": "integer"}, "confirmationMethods": array(string())}
    schemas["Package"] = obj(package_fields, package_fields)
    profile_fields = {key: string() for key in ("id", "code", "name", "type", "status", "description")}
    schemas["SigningProfile"] = obj(profile_fields, profile_fields)
    capability_fields = {"documentTypes": array(string()), "maxFiles": {"type": "integer"},
                         "maxFileBytes": {"type": "integer"}, "maxDecodedBatchBytes": {"type": "integer"},
                         "otpChannel": string(nullable=True), "supportedOtpChannels": array(string()),
                         "partialSuccess": {"type": "boolean"}, "workstations": {"type": "boolean"},
                         "signatureCoordinates": string(), "keywordMatching": string()}
    schemas["ProviderCapabilities"] = obj(capability_fields, capability_fields)
    schemas["CertificateEvent"] = obj({**{key: schemas["PublicCertificate"]["properties"][key]
                                         for key in ("subjectName", "validFrom", "validTo", "status", "certAlias", "personalIdNumber")},
                                        "sessionId": string(), "eventId": string()},
                                       ["subjectName", "validFrom", "validTo", "status", "certAlias", "personalIdNumber", "sessionId", "eventId"])
    schemas["SigningEvent"] = copy.deepcopy(schemas["SigningSession"])
    for key in ("email", "phone", "challengeBase64"):
        schemas["SigningEvent"]["properties"].pop(key)
        if key in schemas["SigningEvent"]["required"]:
            schemas["SigningEvent"]["required"].remove(key)
    schemas["SigningEvent"]["properties"].update({"requestId": string(), "eventId": string()})
    schemas["SigningEvent"]["required"].extend(["requestId", "eventId"])

    keyed = {"/certificates/registration", "/registration-sessions/{sessionId}/confirm", "/signing-requests", "/signing-requests/direct-sign"}
    for path, item in paths.items():
        suffix = path[len(PREFIX):]
        for method, operation in item.items():
            scope = scopes[(method, path)]
            operation["security"] = [{"basic_auth": []}]
            operation["x-required-scope"] = scope
            operation["description"] = f"Requires integration scope `{scope}` and ownership by the authenticated UID. See API_REFERENCE.md."
            operation["parameters"] = [p for p in operation.get("parameters", []) if p.get("name") != "OCS-APIRequest"]
            if suffix in keyed and method == "post":
                operation["parameters"].append({"name": "Idempotency-Key", "in": "header", "required": True,
                                                "schema": string(pattern="^[A-Za-z0-9_.:-]{8,128}$"),
                                                "description": "Creation binds key to input. Confirmation validates header syntax and uses session state; it does not bind key to proof/body."})
            for parameter in operation["parameters"]:
                if parameter["name"] == "personalIdNumber":
                    parameter["schema"] = personal_id
                elif parameter["name"] == "pageIndex":
                    parameter["schema"] = {"type": "integer", "minimum": 1, "default": 1}
                elif parameter["name"] == "pageSize":
                    parameter["schema"] = {"type": "integer", "minimum": 1, "maximum": 100, "default": 20}

            body = operation.get("requestBody", {}).get("content", {}).get("application/json", {}).get("schema")
            if suffix.startswith("/signing-requests") and method == "post" and suffix in ("/signing-requests", "/signing-requests/direct-sign"):
                body = ref("SigningStartInput") if suffix == "/signing-requests" else {"oneOf": [ref("SigningStartInput"), ref("SigningConfirmInput")]}
            elif body is not None:
                fields = body["properties"]
                if suffix == "/certificates/registration":
                    body["required"] = ["personalIdNumber", "fullName", "registrationMethod"]
                    fields["personalIdNumber"] = personal_id
                    fields["fullName"] = string(minLength=1, maxLength=128)
                    fields["registrationMethod"] = string(enum=["CARD_READER", "QR_SELF_UPLOAD"])
                    fields["confirmationMethod"] = confirmation
                elif suffix.endswith(("/identity/info", "/card-reader/info")):
                    body["required"] = ["identifyNumber", "name", "address", "dateOfBirth", "issueDate", "expiredDate", "sex", "isPass"]
                    fields["identifyNumber"] = personal_id
                    for key in ("name", "address", "sex"):
                        fields[key] = string(minLength=1, maxLength=128 if key == "name" else 1024)
                    fields["isPass"] = {"type": "boolean", "enum": [True]}
                    fields["verification"] = receipt
                    fields.update({"phone": string(), "email": string()})
                    fields.update({key: string(maxLength=1024) for key in ("nationality", "nation", "religion", "hometown", "character", "fatherName", "motherName", "partnerName")})
                    fields["mrz"] = string(maxLength=256)
                    fields.update({key: binary for key in ("imageCap", "imageChip", "sodData", "dg1DataBase64", "dg2DataBase64", "dg13DataBase64", "dg14DataBase64", "documentFrontBase64", "documentBackBase64")})
                    fields["score"] = {"type": "integer", "minimum": 0, "maximum": 100} if suffix.endswith("/card-reader/info") else {"type": "number", "nullable": True}
                    if suffix.endswith("/card-reader/info"):
                        body["required"].extend(["imageCap", "imageChip", "sodData", "score"])
                elif suffix.endswith("/reuse-face-match"):
                    body["required"] = ["portraitBase64", "verification"]
                    fields["portraitBase64"] = binary
                    fields["verification"] = obj({**receipt["properties"], "score": {"type": "number"}, "isPass": {"type": "boolean", "enum": [True]}, "sessionId": string()},
                                                 ["score", "isPass", "sessionId", "verifiedAt"])
                elif suffix.endswith("/otp"):
                    fields["otpCode"] = otp
                    body["required"] = ["otpCode"]
                elif suffix.endswith("/revoke"):
                    fields["reasonCode"] = {"type": "integer", "enum": [0, 1, 2, 3, 4, 5, 6, 8, 9, 10], "default": 0}
            if body is not None:
                operation["requestBody"] = {"required": not suffix.endswith(("/confirm", "/revoke")), "content": {"application/json": {"schema": body}}}

            if suffix == "/certificates/packages":
                data, listing = array(ref("Package")), True
            elif suffix == "/signing-profiles":
                data, listing = array(ref("SigningProfile")), True
            elif suffix in ("/certificates", "/certificates/mine"):
                data, listing = array(ref("PublicCertificate")), True
            elif suffix == "/capabilities":
                data, listing = ref("ProviderCapabilities"), False
            elif suffix.endswith("/public"):
                data, listing = ref("ProviderPublicCertificate"), False
            elif suffix.startswith("/certificates/{certAlias}"):
                data, listing = ref("PublicCertificate"), False
            elif suffix == "/certificates/dossier-reuse":
                data, listing = ref("PublicReuseInfo"), False
            elif suffix.startswith("/signing-requests"):
                data, listing = ref("SigningDocumentResult" if suffix.endswith("/result") else "SigningSession"), False
            elif suffix.endswith(("/identity/info", "/card-reader/info")):
                data, listing = ref("PublicIdentityResult"), False
            else:
                data, listing = ref("PublicRegistration"), False
            if suffix.endswith(("/identity/info", "/card-reader/info")):
                operation["responses"]["413"] = {"description": "Decoded identity evidence exceeds 20 MiB."}
            for status, response in operation["responses"].items():
                response["content"] = {"application/json": {"schema": envelope(data, listing) if int(status) < 300 else ref("ErrorEnvelope")}}

            if method == "post" and suffix in ("/certificates/registration", "/signing-requests", "/signing-requests/direct-sign"):
                event = "CertificateEvent" if suffix == "/certificates/registration" else "SigningEvent"
                operation["callbacks"] = {"completion": {"{$request.body#/callbackUrl}": {"post": {
                    "summary": "Optional flat JSON completion event; validate HMAC over raw body and deduplicate eventId.",
                    "security": [], "parameters": [
                        {"in": "header", "name": "X-Event-Id", "required": True, "schema": string()},
                        {"in": "header", "name": "X-Event-Signature", "required": True, "schema": string(pattern="^[a-f0-9]{64}$"),
                         "description": "Hex HMAC-SHA256 of exact request bytes using the shared callbackSecret; no sha256= prefix."}],
                    "requestBody": {"required": True, "content": {"application/json": {"schema": ref(event)}}},
                    "responses": {"2XX": {"description": "Event durably accepted, including an already processed eventId."}}}}}}

    # Export only schemas reachable from the public operations and callbacks.
    used = set()

    def collect(value):
        if isinstance(value, dict):
            if "$ref" in value:
                name = value["$ref"].removeprefix("#/components/schemas/")
                if name not in used:
                    used.add(name)
                    collect(schemas[name])
            for child in value.values():
                collect(child)
        elif isinstance(value, list):
            for child in value:
                collect(child)

    collect(paths)
    collection = json.loads((ROOT / "deploy/examples/libresign-public.postman_collection.json").read_text())
    requests = []

    def walk(items):
        for item in items:
            if "item" in item:
                walk(item["item"])
            else:
                requests.append(item["request"])

    walk(collection["item"])
    covered = set()
    for request in requests:
        url = request["url"]
        raw = url["raw"] if isinstance(url, dict) else url
        suffix = raw.removeprefix("{{baseUrl}}").split("?", 1)[0]
        candidates = []
        for method, path in inventory:
            route = path[len(PREFIX):]
            pattern = re.sub(r"\{[^}]+\}", r"[^/]+", route)
            if method == request["method"].lower() and re.fullmatch(pattern, suffix):
                candidates.append((len(re.sub(r"\{[^}]+\}", "", route)), method, path))
        if not candidates:
            raise ValueError(f"Postman request has no public route: {raw}")
        # A literal /certificates/packages must not count as /certificates/{certAlias}.
        _, method, path = max(candidates)
        covered.add((method, path))
    if covered != inventory:
        raise ValueError(f"Postman does not cover public operations: {inventory - covered}")
    version = ET.parse(ROOT / "appinfo/info.xml").getroot().findtext("version")
    return {"openapi": "3.0.3", "info": {"title": "LibreSign Public Certificate and PDF Signing API", "version": version,
            "description": "Generated by deploy/export-public-api.py from this checkout's openapi.json and service-level documentation overrides. Basic authentication is required on every operation. Read deploy/INTEGRATION.md and deploy/API_REFERENCE.md together with this contract; use the exact deployed revision.",
            "license": source["info"]["license"]},
            "servers": [{"url": "{origin}", "variables": {"origin": {"default": "https://sign.example.com", "description": "Nextcloud origin, including port and optional installation subdirectory; exclude /index.php/apps/libresign/api/v1/public."}}}],
            "security": [{"basic_auth": []}], "paths": paths,
            "components": {"securitySchemes": {"basic_auth": source["components"]["securitySchemes"]["basic_auth"]},
                           "schemas": {name: schemas[name] for name in sorted(used)}}}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--check", action="store_true", help="Verify the committed export without writing files")
    args = parser.parse_args()
    document = export()
    payload = json.dumps(document, ensure_ascii=False, indent=2) + "\n"
    if args.check:
        if not OUTPUT.exists() or OUTPUT.read_text() != payload:
            raise SystemExit("Public OpenAPI is stale; run python3 deploy/export-public-api.py")
        count = sum(len(methods) for methods in document["paths"].values())
        print(f"Public OpenAPI is current; controller/OpenAPI/Postman inventories match ({count} operations).")
    else:
        OUTPUT.write_text(payload)
        print(f"Exported {OUTPUT.relative_to(ROOT)}")


if __name__ == "__main__":
    main()
