"""Build and verify the attached WordPress package; never publish a release."""
import argparse
import hashlib
from pathlib import Path, PurePosixPath
import re
from zipfile import ZIP_DEFLATED, ZipFile, ZipInfo


UPDATE_URI = "https://github.com/gnznovus/SyndaMorph"
VERSION = r"(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)"
ROOT = Path(__file__).resolve().parents[1]


def release_notes(plugin_dir, tag, require_release_date=False):
    match = re.fullmatch(rf"v?({VERSION})", tag)
    if not match:
        raise ValueError("Release tag must be vMAJOR.MINOR.PATCH (stable only)")
    version = match[1]
    plugin = (plugin_dir / "syndamorph.php").read_text(encoding="utf-8")
    headers = dict(re.findall(r"^\s*\* ([\w ]+):\s*([^\r\n]+)", plugin, re.M))
    if headers.get("Version", "").strip() != version:
        raise ValueError(f"Plugin header version must match release {version}")
    if headers.get("Update URI", "").strip() != UPDATE_URI:
        raise ValueError("Missing native update URI")
    changelog = (plugin_dir / "CHANGELOG.md").read_text(encoding="utf-8")
    entries = list(re.finditer(r"^## \[([^\]\n]+)\]([^\n]*)\n(.*?)(?=^## \[|\Z)", changelog, re.M | re.S))
    if not entries or entries[0][1] != version or sum(e[1] == version for e in entries) != 1:
        raise ValueError(f"Top changelog entry must be unique and match [{version}]")
    entry = entries[0]
    if not entry[3].strip():
        raise ValueError(f"CHANGELOG.md requires a nonempty [{version}] entry")
    if require_release_date:
        from datetime import date
        heading = re.fullmatch(r" - (\d{4}-\d{2}-\d{2})", entry[2])
        if not heading:
            raise ValueError("Published release requires a dated changelog heading")
        date.fromisoformat(heading[1])
    return entry[0].strip() + "\n"


def plugin_files(plugin_dir):
    files = {}
    for path in sorted(plugin_dir.rglob("*")):
        if path.is_symlink():
            raise ValueError(f"Symlinks are not allowed: {path}")
        if path.is_file():
            name = "syndamorph/" + path.relative_to(plugin_dir).as_posix()
            if "\\" in name or ".." in PurePosixPath(name).parts:
                raise ValueError(f"Invalid package path: {name}")
            files[name] = path.read_bytes()
    for required in ("syndamorph.php", "CHANGELOG.md", "includes/updater.php"):
        if "syndamorph/" + required not in files:
            raise ValueError(f"Missing required plugin file: {required}")
    return files


def validate_zip(package, files):
    with ZipFile(package) as archive:
        names = archive.namelist()
        if len(names) != len(set(names)) or set(names) != set(files):
            raise ValueError("ZIP contents must exactly match the plugin files")
        for name in names:
            if not name.startswith("syndamorph/") or "\\" in name or ".." in PurePosixPath(name).parts:
                raise ValueError(f"Invalid ZIP path: {name}")
            if archive.read(name) != files[name]:
                raise ValueError(f"ZIP file differs from source: {name}")


def build_plugin(plugin_dir, tag, output_dir, require_release_date=False):
    notes = release_notes(plugin_dir, tag, require_release_date)
    files = plugin_files(plugin_dir)
    output_dir.mkdir(parents=True, exist_ok=True)
    package = output_dir / "syndamorph.zip"
    notes_file = output_dir / "release-notes.md"
    checksum_file = output_dir / "syndamorph.zip.sha256"
    if any(path.exists() for path in (package, notes_file, checksum_file)):
        raise ValueError("Use a fresh output directory; existing artifacts are never overwritten")
    with ZipFile(package, "x", compression=ZIP_DEFLATED) as archive:
        for name, content in files.items():
            # Fix archive metadata and preserve source bytes on every platform.
            info = ZipInfo(name, date_time=(1980, 1, 1, 0, 0, 0))
            info.create_system = 3
            info.external_attr = 0o100644 << 16
            info.compress_type = ZIP_DEFLATED
            archive.writestr(info, content)
    validate_zip(package, files)
    notes_file.write_text(notes, encoding="utf-8", newline="\n")
    checksum = hashlib.sha256(package.read_bytes()).hexdigest()
    checksum_file.write_text(f"{checksum}  syndamorph.zip\n", encoding="ascii", newline="\n")
    return package


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--tag", required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--require-release-date", action="store_true")
    args = parser.parse_args()
    try:
        package = build_plugin(ROOT / "plugin", args.tag, args.output, args.require_release_date)
    except (ValueError, OSError) as error:
        parser.exit(1, f"Release validation failed: {error}\n")
    print(f"Validated {package}")
