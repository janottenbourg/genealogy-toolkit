import json
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).parent.parent
TOOL = ROOT / "tools" / "export_augment.py"
SAMPLE = ROOT / "sample.ged"

sys.path.insert(0, str(ROOT / "tools"))
import export_augment as ea  # noqa: E402


def test_escape_at_doubles_at_signs():
    assert ea.escape_at("jan@ottenbourg.com") == "jan@@ottenbourg.com"
    assert ea.escape_at("no-at-here") == "no-at-here"
    assert ea.escape_at("@@") == "@@@@"


def test_split_value_empty_returns_single_empty_chunk():
    assert ea.split_value("") == [""]


def test_split_value_short_string_one_chunk():
    assert ea.split_value("hello", max_bytes=200) == ["hello"]


def test_split_value_splits_long_ascii():
    s = "a" * 250
    chunks = ea.split_value(s, max_bytes=200)
    assert chunks == ["a" * 200, "a" * 50]
    assert "".join(chunks) == s


def test_split_value_never_breaks_multibyte_char():
    # 'é' is 2 bytes in UTF-8; 150 of them = 300 bytes, budget 200.
    s = "é" * 150
    chunks = ea.split_value(s, max_bytes=200)
    assert "".join(chunks) == s
    for c in chunks:
        assert len(c.encode("utf-8")) <= 200
        c.encode("utf-8").decode("utf-8")  # each chunk is valid UTF-8


def test_encode_first_line_is_note():
    assert ea.encode_logical_lines(["alpha"]) == ["1 NOTE alpha"]


def test_encode_subsequent_lines_are_cont():
    assert ea.encode_logical_lines(["alpha", "beta"]) == [
        "1 NOTE alpha",
        "2 CONT beta",
    ]


def test_encode_empty_logical_line_has_no_trailing_space():
    assert ea.encode_logical_lines(["alpha", ""]) == [
        "1 NOTE alpha",
        "2 CONT",
    ]


def test_encode_long_line_uses_conc():
    long = "x" * 250
    out = ea.encode_logical_lines([long])
    assert out[0] == "1 NOTE " + "x" * 200
    assert out[1] == "2 CONC " + "x" * 50


def test_has_any_true_when_one_field_set():
    assert ea.has_any({"email": "x@y.com"}) is True
    assert ea.has_any({"bio": "hi"}) is True


def test_has_any_false_when_all_empty_or_whitespace():
    assert ea.has_any({}) is False
    assert ea.has_any({"email": "", "bio": "  "}) is False


def test_build_block_omits_empty_fields_and_uses_dutch_labels():
    block = ea.build_block_lines({
        "email": "jan@ottenbourg.com",
        "mobile": "",
        "facebook": "https://facebook.com/janottenbourg",
        "linkedin": "",
        "instagram": "",
        "bio": "",
    })
    assert block[0] == "1 NOTE " + ea.BEGIN_MARKER
    assert "2 CONT E-mail: jan@@ottenbourg.com" in block
    assert "2 CONT Facebook: https://facebook.com/janottenbourg" in block
    assert block[-1] == "2 CONT " + ea.END_MARKER
    # No empty fields emitted:
    assert not any("Mobiel:" in ln for ln in block)
    assert not any("LinkedIn:" in ln for ln in block)


def test_build_block_multiline_bio_becomes_multiple_cont():
    block = ea.build_block_lines({"bio": "Regel een.\nRegel twee."})
    assert "2 CONT Bio: Regel een." in block
    assert "2 CONT Regel twee." in block


def test_build_block_field_order_is_deterministic():
    block = ea.build_block_lines({
        "instagram": "https://instagram.com/x",
        "email": "a@b.com",
    })
    email_idx = next(i for i, ln in enumerate(block) if "E-mail:" in ln)
    insta_idx = next(i for i, ln in enumerate(block) if "Instagram:" in ln)
    assert email_idx < insta_idx


def test_parse_records_groups_by_level_zero():
    lines = [
        "0 HEAD",
        "1 SOUR Geneanet",
        "0 @I1@ INDI",
        "1 NAME Jan /Test/",
        "0 TRLR",
    ]
    recs = ea.parse_records(lines)
    assert len(recs) == 3
    assert recs[0] == ["0 HEAD", "1 SOUR Geneanet"]
    assert recs[1] == ["0 @I1@ INDI", "1 NAME Jan /Test/"]
    assert recs[2] == ["0 TRLR"]


def test_record_header_indi():
    assert ea.record_header(["0 @I7@ INDI", "1 NAME x"]) == ("I7", "INDI")


def test_record_header_head_and_trlr():
    assert ea.record_header(["0 HEAD"]) == (None, "HEAD")
    assert ea.record_header(["0 TRLR"]) == (None, "TRLR")
