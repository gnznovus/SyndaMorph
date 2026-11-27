"""Validate the staging Compose contract without starting services."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile


def main():
    compose = Path(__file__).resolve().parents[1] / "compose.plesk-staging.yaml"
    environment = {
        key: value for key, value in os.environ.items()
        if not key.upper().startswith(("SYNDAMORPH_", "COMPOSE_"))
    }
    revision = "0123456789abcdef0123456789abcdef01234567"
    credentials = {
        "backend": "SYNDAMORPH_BACKEND_TOKEN",
        "renderer": "SYNDAMORPH_RENDERER_TOKEN",
        "gateway": "SYNDAMORPH_GATEWAY_ADMIN_TOKEN",
    }
    settings = {
        "SYNDAMORPH_BUILD_SHA": revision,
        "SYNDAMORPH_CAPTURE_HOSTS": "capture.example.invalid",
        "SYNDAMORPH_INSTALL_SINGLEFILE": "1",
        "SYNDAMORPH_GATEWAY_PEPPER": "gateway-storage-secret-fixture-v1",
        **{key: f"{service}-compose-credential-fixture-v1" for service, key in credentials.items()},
    }
    with tempfile.TemporaryDirectory() as temporary:
        parent = Path(temporary).resolve()
        source = parent / "frozen"
        source.mkdir()
        for context in ("backend", "renderer"):
            (source / context).mkdir()
        shutil.copyfile(compose, source / compose.name)

        def run(*args):
            return subprocess.run(
                ["docker", "compose", "-f", str(source / compose.name), *args],
                cwd=parent, env=environment, capture_output=True, text=True, timeout=30,
            )

        dotenv = source / ".env"
        dotenv.write_text("".join(f"{key}={value}\n" for key, value in settings.items()), encoding="utf-8")
        inactive = run("config", "--services")
        assert inactive.returncode == 0, "Compose failed to load the adjacent .env"
        assert set(inactive.stdout.split()) == {"backend", "gateway"}, "Renderer profile started by default"

        active = run("--profile", "authenticated-renderer", "config", "--format", "json")
        assert active.returncode == 0, "Staging Compose failed to resolve"
        services = json.loads(active.stdout)["services"]
        assert set(services) == set(credentials), "Unexpected services in the staging stack"
        for service, key in credentials.items():
            spec = services[service]
            context = "renderer" if service == "renderer" else "backend"
            port = {"backend": 3100, "renderer": 3000, "gateway": 3200}[service]
            assert Path(spec["build"]["context"]).resolve() == source / context, "Build escaped the frozen tree"
            assert spec["build"]["labels"]["org.opencontainers.image.revision"] == revision
            assert spec["image"] == f"syndamorph-playground-{service}:{revision}"
            assert spec["environment"][key] == settings[key], "Service credential did not come from .env"
            assert len(spec["ports"]) == 1, "Unexpected published ports"
            binding = spec["ports"][0]
            assert (binding["host_ip"], int(binding["published"]), binding["target"]) == ("127.0.0.1", port, port)
        assert services["backend"]["build"]["args"]["SYNDAMORPH_INSTALL_SINGLEFILE"] == "1"
        assert services["backend"]["environment"]["SYNDAMORPH_REFERENCE_ARCHIVE"] == "1"
        assert services["gateway"]["environment"]["SYNDAMORPH_GATEWAY_PEPPER"] == settings["SYNDAMORPH_GATEWAY_PEPPER"]

        for required in (*credentials.values(), "SYNDAMORPH_GATEWAY_PEPPER"):
            dotenv.write_text("".join(f"{key}={value}\n" for key, value in settings.items() if key != required),
                              encoding="utf-8")
            missing = run("--profile", "authenticated-renderer", "config", "--quiet")
            assert missing.returncode != 0 and required in missing.stderr, f"Compose accepted missing {required}"
    print("PASS: staging Compose contexts, adjacent .env, required secrets, matching revisions and loopback profile")


if __name__ == "__main__":
    main()
