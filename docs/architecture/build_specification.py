"""Validate the design handoff and assemble its 21 ordered source documents."""

from pathlib import Path
import json
import re


ROOT = Path(__file__).resolve().parent
ENTITY_NAMES = (
    "CustomerProfile CustomerIdentity ConsentRecord BehaviorEvent Campaign "
    "CampaignVersion CampaignAsset CampaignGoal Audience Segment SegmentRule "
    "Automation AutomationVersion AutomationNode AutomationEdge AutomationRun "
    "AutomationStepRun Channel Provider Message Delivery TrackingLink Touchpoint "
    "Conversion AttributionResult Experiment ExperimentVariant ExperimentAssignment "
    "Promotion Referral Affiliate Influencer Reward LoyaltyLedgerEntry OfflinePlacement "
    "QRAsset Metric"
).split()


def visible_markdown(text: str) -> str:
    """Exclude illustrative fenced code from local-file link validation."""
    return re.sub(r"^```[^\n]*\n.*?^```\s*$", "", text, flags=re.M | re.S)


def validate_local_links(path: Path, text: str) -> None:
    for target in re.findall(r"\]\(([^)]+)\)", visible_markdown(text)):
        target = target.split("#", 1)[0]
        if not target or re.match(r"^[a-zA-Z][a-zA-Z0-9+.-]*:", target):
            continue
        if not (path.parent / target).resolve().exists():
            raise ValueError(f"Broken local link in {path.name}: {target}")


def validate_markdown(path: Path, text: str) -> None:
    """Catch broken GFM tables and malformed illustrative JSON."""
    expected = None
    for line_number, line in enumerate(visible_markdown(text).splitlines(), 1):
        if not line.startswith("|"):
            expected = None
            continue
        separators = len(re.findall(r"(?<!\\)\|", line))
        if expected is None:
            expected = separators
        if separators != expected:
            raise ValueError(f"Malformed Markdown table in {path.name}:{line_number}")
    for example in re.findall(r"^```json\n(.*?)^```", text, re.M | re.S):
        json.loads(example)


def main() -> None:
    sources = sorted((ROOT / "phases").glob("[0-9][0-9]-*.md"))
    if len(sources) != 21:
        raise ValueError(f"Expected 21 phase documents, found {len(sources)}")

    chapters = []
    contents = []
    for number, path in enumerate(sources, 1):
        text = path.read_text(encoding="utf-8").strip()
        match = re.match(rf"## Phase {number} — (.+)\n", text)
        if not match or not path.name.startswith(f"{number:02d}-"):
            raise ValueError(f"Unexpected phase order/header: {path.name}")
        if len(text.split()) < 700:
            raise ValueError(f"Phase {number} is unexpectedly short")
        validate_local_links(path, text)
        validate_markdown(path, text)
        if number == 5:
            missing = [name for name in ENTITY_NAMES if name not in text]
            if missing:
                raise ValueError(f"Domain entity coverage missing: {missing}")
        if number == 18 and len(re.findall(r"^### ADR-\d{3}", text, re.M)) < 13:
            raise ValueError("Expected all 13 required ADRs")
        title = match.group(1)
        anchor = f"phase-{number}"
        contents.append(f"{number}. [{title}](#{anchor})")
        text = text.replace("(../sources.md)", "(#official-reference-register)")
        chapters.append(f'<a id="{anchor}"></a>\n\n{text}')

    register_path = ROOT / "sources.md"
    register = register_path.read_text(encoding="utf-8").strip()
    validate_local_links(register_path, register)
    validate_markdown(register_path, register)
    register = register.replace("# Official reference register", "## Official reference register", 1)

    header = (
        "# WooCommerce Marketing Operating System — implementation specification\n\n"
        "Design snapshot: **2026-10-04 (Asia/Tehran)**. Working plugin slug/text domain: "
        "`woocommerce-marketing-os`. Native WordPress/WooCommerce modular monolith.\n\n"
        "This is the reviewed design handoff, with all 21 phases in the requested order. "
        "It specifies future implementation and release acceptance; it does not claim "
        "an implemented plugin or completed compatibility, performance, security or "
        "accessibility tests. Public API verification and implementation gates are "
        "distinguished throughout.\n\n"
        "Generated from the editable [phase documents](phases/) by "
        "[build_specification.py](build_specification.py).\n\n"
        "## Contents\n\n"
    )
    result = header + "\n".join(contents) + "\n\n" + "\n\n---\n\n".join(chapters)
    result += "\n\n---\n\n" + register + "\n"
    output = ROOT / "SPECIFICATION.md"
    output.write_text(result, encoding="utf-8")
    validate_local_links(output, result)
    validate_markdown(output, result)
    validate_local_links(ROOT.parents[1] / "README.md", (ROOT.parents[1] / "README.md").read_text())
    print(
        f"Validated 21 ordered phases, {len(ENTITY_NAMES)} required entity names, "
        f"13+ ADRs, local links, Markdown tables and JSON examples. "
        f"Wrote {output.name}: {len(result.split()):,} words."
    )


if __name__ == "__main__":
    main()
