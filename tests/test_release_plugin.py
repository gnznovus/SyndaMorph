"""Offline release validation tests using temporary copies of the real plugin."""
import hashlib
from pathlib import Path
import shutil
import sys
import tempfile
import unittest
import warnings
from zipfile import ZipFile

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from release_plugin import build_plugin, plugin_files, release_notes, validate_zip


class ReleasePluginTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.plugin = self.root / "plugin"
        shutil.copytree(Path(__file__).resolve().parents[1] / "plugin", self.plugin)
        # Tests keep working as the release version evolves.
        import re
        self.version = re.search(r"\* Version: ([^\s]+)", (self.plugin / "syndamorph.php").read_text(encoding="utf-8"))[1]
        self.tag = "v" + self.version
        self.out = self.root / "build"

    def change(self, name, old, new):
        path = self.plugin / name
        text = path.read_text(encoding="utf-8")
        self.assertIn(old, text)
        path.write_text(text.replace(old, new, 1), encoding="utf-8")

    def test_real_package_paths_contents_notes_and_checksum(self):
        package = build_plugin(self.plugin, self.tag, self.out)
        with ZipFile(package) as archive:
            self.assertEqual(set(archive.namelist()), set(plugin_files(self.plugin)))
            self.assertTrue(all(n.startswith("syndamorph/") and "\\" not in n for n in archive.namelist()))
            self.assertEqual(archive.read("syndamorph/CHANGELOG.md"), (self.plugin / "CHANGELOG.md").read_bytes())
            self.assertEqual(archive.read("syndamorph/assets/import.js"), (self.plugin / "assets/import.js").read_bytes())
        notes = (self.out / "release-notes.md").read_text(encoding="utf-8")
        self.assertTrue(notes.startswith(f"## [{self.version}]"))
        self.assertEqual(notes, release_notes(self.plugin, self.tag))
        digest = hashlib.sha256(package.read_bytes()).hexdigest()
        self.assertEqual((self.out / "syndamorph.zip.sha256").read_text(), f"{digest}  syndamorph.zip\n")

    def test_deterministic_build(self):
        a = build_plugin(self.plugin, self.tag, self.out)
        b = build_plugin(self.plugin, self.tag, self.root / "other")
        self.assertEqual(a.read_bytes(), b.read_bytes())

    def test_bare_stable_tag(self):
        self.assertEqual(release_notes(self.plugin, self.tag), release_notes(self.plugin, self.version))

    def test_reject_unstable_tags(self):
        for tag in ("main", self.tag + "-rc.1", self.tag + "+build", self.tag + "\n", "v01.2.3", "v1.2"):
            with self.subTest(tag=tag), self.assertRaises(ValueError):
                build_plugin(self.plugin, tag, self.out)
        self.assertFalse(self.out.exists())

    def test_header_mismatch(self):
        with self.assertRaisesRegex(ValueError, "header version"):
            build_plugin(self.plugin, "v999.999.999", self.out)

    def test_update_uri_mismatch(self):
        self.change("syndamorph.php", "Update URI: https://github.com/gnznovus/SyndaMorph", "Update URI: https://example.com")
        with self.assertRaisesRegex(ValueError, "update URI"):
            build_plugin(self.plugin, self.tag, self.out)

    def test_missing_changelog(self):
        (self.plugin / "CHANGELOG.md").unlink()
        with self.assertRaises(FileNotFoundError):
            build_plugin(self.plugin, self.tag, self.out)

    def test_changelog_version_mismatch(self):
        self.change("CHANGELOG.md", f"## [{self.version}]", "## [999.999.999]")
        with self.assertRaisesRegex(ValueError, "Top changelog"):
            build_plugin(self.plugin, self.tag, self.out)

    def test_empty_entry(self):
        (self.plugin / "CHANGELOG.md").write_text(f"## [{self.version}]\n\n## [0.0.0]\nOlder\n", encoding="utf-8")
        with self.assertRaisesRegex(ValueError, "nonempty"):
            build_plugin(self.plugin, self.tag, self.out)

    def test_duplicate_entry(self):
        with (self.plugin / "CHANGELOG.md").open("a", encoding="utf-8") as changelog:
            changelog.write(f"\n## [{self.version}]\nDuplicate\n")
        with self.assertRaisesRegex(ValueError, "unique"):
            build_plugin(self.plugin, self.tag, self.out)

    def test_published_release_requires_date(self):
        (self.plugin / "CHANGELOG.md").write_text(f"## [{self.version}] - Unreleased\n\nChanges\n", encoding="utf-8")
        with self.assertRaisesRegex(ValueError, "dated"):
            build_plugin(self.plugin, self.tag, self.out, require_release_date=True)
        self.assertFalse(self.out.exists())

    def test_dated_release_passes(self):
        (self.plugin / "CHANGELOG.md").write_text(f"## [{self.version}] - 2026-11-20\n\nChanges\n", encoding="utf-8")
        build_plugin(self.plugin, self.tag, self.out, require_release_date=True)
        self.assertEqual((self.out / "release-notes.md").read_text(), f"## [{self.version}] - 2026-11-20\n\nChanges\n")

    def test_invalid_release_date(self):
        (self.plugin / "CHANGELOG.md").write_text(f"## [{self.version}] - 2026-02-31\n\nChanges\n", encoding="utf-8")
        with self.assertRaises(ValueError):
            build_plugin(self.plugin, self.tag, self.out, require_release_date=True)

    def test_missing_updater(self):
        (self.plugin / "includes" / "updater.php").unlink()
        with self.assertRaisesRegex(ValueError, "Missing required"):
            build_plugin(self.plugin, self.tag, self.out)

    def test_existing_output_is_preserved(self):
        package = build_plugin(self.plugin, self.tag, self.out)
        original = package.read_bytes()
        with self.assertRaisesRegex(ValueError, "never overwritten"):
            build_plugin(self.plugin, self.tag, self.out)
        self.assertEqual(package.read_bytes(), original)

    def test_reject_bad_archives(self):
        files = plugin_files(self.plugin)
        for name in ("SyndaMorph/syndamorph.php", "plugin/syndamorph.php", "syndamorph\\syndamorph.php", "syndamorph/../syndamorph.php"):
            with self.subTest(name=name):
                package = self.root / "bad.zip"
                with ZipFile(package, "w") as archive:
                    archive.writestr(name, "bad")
                with self.assertRaises(ValueError):
                    validate_zip(package, files)

    def test_reject_duplicate_and_corrupt_entries(self):
        files = plugin_files(self.plugin)
        for duplicate in (True, False):
            package = self.root / "bad.zip"
            with warnings.catch_warnings(), ZipFile(package, "w") as archive:
                warnings.simplefilter("ignore", UserWarning)
                for name, content in files.items():
                    archive.writestr(name, content if duplicate else b"corrupt")
                if duplicate:
                    archive.writestr("syndamorph/CHANGELOG.md", b"duplicate")
            with self.assertRaises(ValueError):
                validate_zip(package, files)


if __name__ == "__main__":
    unittest.main()
