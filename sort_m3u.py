import argparse
import re
import unicodedata
from pathlib import Path


ATTR_RE = re.compile(r'([a-zA-Z0-9_-]+)="([^"]*)"')
DEFAULT_ORDER_FILE = "reference-order.m3u"


def norm(text: str) -> str:
    if not text:
        return ""
    text = unicodedata.normalize("NFKD", text).encode("ascii", "ignore").decode("ascii")
    text = text.lower().replace("_", " ")
    text = re.sub(r"\b(pl|polska|poland)\b", " ", text)
    text = re.sub(r"\b(hd|fhd|uhd|4k\+?|1080p|sd|backup|ppv)\b", " ", text)
    text = re.sub(r"\b(channel)\b", " ", text)
    text = text.replace("&", " and ").replace("+", " plus ")
    text = re.sub(r"[^a-z0-9]+", " ", text)
    return re.sub(r"\s+", " ", text).strip()


def aliases(key: str) -> list[str]:
    out = [key]
    pairs = [
        ("paramount channel", "paramount network"),
        ("canal 1", "canal plus1"),
        ("canal sport 1 plus", "canal plus sport"),
        ("canal sport 2", "canal plus sport 2"),
        ("canal seriale", "canal plus seriale"),
        ("canal family", "canal plus family"),
        ("hbo 2", "hbo2"),
        ("hbo 3", "hbo3"),
        ("baby", "baby tv"),
        ("nick music", "nickmusic"),
        ("viaplay 1", "viaplay"),
        ("dtx hd", "dtx"),
        ("fox comedy", "fx comedy"),
        ("fox", "fx"),
        ("nat geo", "national geo"),
        ("national geographic people", "nat geo people"),
        ("discovery historia", "historia"),
        ("discovery science", "science"),
        ("golf channel", "golf zone"),
        ("polsat romance", "romance"),
        ("h2", "history 2"),
        ("scifi universal", "scifi"),
        ("sport klub", "sportklub"),
        ("mtv live", "mtv"),
        ("warner", "warnertv"),
    ]
    for src, dst in pairs:
        if src in key:
            out.append(key.replace(src, dst))
    if key == "4":
        out.append("tv4")
    if key == "6":
        out.append("tv6")
    return list(dict.fromkeys(v for v in out if v))


def parse_attrs(line: str) -> dict[str, str]:
    return {k: v for k, v in ATTR_RE.findall(line)}


def set_attr(line: str, key: str, value: str) -> str:
    if re.search(rf'{re.escape(key)}="[^"]*"', line):
        return re.sub(rf'{re.escape(key)}="[^"]*"', f'{key}="{value}"', line)
    if line.startswith("#EXTINF:"):
        return line.replace("#EXTINF:-1 ", f'#EXTINF:-1 {key}="{value}" ', 1)
    return line


def strip_pl_prefix(text: str) -> str:
    text = re.sub(r"^\s*pl\s*[:|]\s*", "", text, flags=re.I)
    text = re.sub(r"^\s*pl\s+", "", text, flags=re.I)
    return text.strip()


def parse_entries(lines: list[str]) -> tuple[list[str], list[list[str]]]:
    preamble: list[str] = []
    entries: list[list[str]] = []
    i = 0
    while i < len(lines):
        line = lines[i]
        if line.startswith("#EXTINF:"):
            block = [line]
            i += 1
            while i < len(lines) and not lines[i].startswith("#EXTINF:"):
                block.append(lines[i])
                i += 1
            entries.append(block)
        else:
            if not entries:
                preamble.append(line)
            i += 1
    return preamble, entries


def primary_entry_key(extinf: str) -> str:
    attrs = parse_attrs(extinf)
    display = extinf.split(",", 1)[1].strip() if "," in extinf else ""
    for candidate in (attrs.get("tvg-name", ""), display, attrs.get("tvg-id", "")):
        key = norm(candidate)
        if key:
            return key
    return ""


def entry_names(extinf: str) -> list[str]:
    attrs = parse_attrs(extinf)
    display = extinf.split(",", 1)[1].strip() if "," in extinf else ""
    names = [attrs.get("tvg-name", ""), display, attrs.get("tvg-id", "")]
    names.extend([strip_pl_prefix(x) for x in names if x])
    return [x for x in names if x]


def token_best(key: str, known: list[str], min_score: float = 0.66) -> str | None:
    key_t = set(key.split())
    if len(key_t) < 2:
        return None
    best = None
    best_score = 0.0
    for k in known:
        t = set(k.split())
        if not t:
            continue
        common = len(key_t & t)
        if common == 0:
            continue
        score = common / max(len(key_t), len(t))
        if score > best_score:
            best_score = score
            best = k
    return best if best_score >= min_score else None


def build_alias_lookup(order_keys: list[str]) -> dict[str, str]:
    lookup: dict[str, str] = {}
    for canonical in order_keys:
        lookup.setdefault(canonical, canonical)
        for v in aliases(canonical):
            lookup.setdefault(v, canonical)
    return lookup


def classify_key(extinf: str, known_keys: list[str], alias_lookup: dict[str, str]) -> str | None:
    for name in entry_names(extinf):
        base = norm(name)
        if not base:
            continue
        if base in alias_lookup:
            return alias_lookup[base]
        for variant in aliases(base):
            if variant in alias_lookup:
                return alias_lookup[variant]
        hit = token_best(base, known_keys)
        if hit:
            return hit
    return None


def load_order_file(path: Path) -> list[str]:
    if not path.exists():
        return []
    lines = path.read_text(encoding="utf-8", errors="ignore").splitlines()
    if any(line.startswith("#EXTINF:") for line in lines):
        _, entries = parse_entries(lines)
        out: list[str] = []
        seen: set[str] = set()
        for block in entries:
            key = primary_entry_key(block[0])
            if key and key not in seen:
                seen.add(key)
                out.append(key)
        return out
    out: list[str] = []
    seen: set[str] = set()
    for line in lines:
        key = norm(line)
        if key and key not in seen:
            seen.add(key)
            out.append(key)
    return out


def build_local_icon_map(m3u_path: Path) -> dict[str, str]:
    result: dict[str, str] = {}
    if not m3u_path.exists():
        return result
    lines = m3u_path.read_text(encoding="utf-8", errors="ignore").splitlines()
    _, entries = parse_entries(lines)
    for block in entries:
        extinf = block[0]
        attrs = parse_attrs(extinf)
        logo = attrs.get("tvg-logo", "")
        if not logo.startswith("./"):
            continue
        for n in entry_names(extinf):
            k = norm(n)
            if not k:
                continue
            for v in aliases(k):
                result.setdefault(v, logo)
    return result


def build_file_icon_map(base_dir: Path) -> dict[str, str]:
    result: dict[str, str] = {}
    for p in sorted(base_dir.glob("*.png")):
        key = norm(p.stem)
        if not key:
            continue
        logo = f"./{p.name}"
        for v in aliases(key):
            result.setdefault(v, logo)
    return result


def resolve_logo(extinf: str, key: str | None, icon_map: dict[str, str], file_icon_map: dict[str, str]) -> str:
    attrs = parse_attrs(extinf)
    existing_logo = attrs.get("tvg-logo", "")

    if key:
        logo = icon_map.get(key) or file_icon_map.get(key)
        if logo:
            return logo

    for n in entry_names(extinf):
        k = norm(n)
        if not k:
            continue
        for v in aliases(k):
            logo = icon_map.get(v) or file_icon_map.get(v)
            if logo:
                return logo

    return existing_logo


def main() -> None:
    parser = argparse.ArgumentParser(description="Sort M3U according to reference playlists and set local logos.")
    parser.add_argument("--input", default="input.m3u")
    parser.add_argument("--output", default="output.m3u")
    parser.add_argument("--icons-source", default="input.m3u")
    parser.add_argument("--order-file", default=DEFAULT_ORDER_FILE)
    args = parser.parse_args()

    base = Path(__file__).resolve().parent
    input_path = (base / args.input).resolve() if not Path(args.input).is_absolute() else Path(args.input)
    output_path = (base / args.output).resolve() if not Path(args.output).is_absolute() else Path(args.output)
    icons_source = (base / args.icons_source).resolve() if not Path(args.icons_source).is_absolute() else Path(args.icons_source)
    order_file = (base / args.order_file).resolve() if not Path(args.order_file).is_absolute() else Path(args.order_file)

    lines = input_path.read_text(encoding="utf-8", errors="ignore").splitlines()
    preamble, entries = parse_entries(lines)

    order = load_order_file(order_file)
    if not order:
        raise SystemExit(f"Order file is missing or empty: {order_file}")
    order_index = {k: i for i, k in enumerate(order)}
    known_keys = list(order_index.keys())
    alias_lookup = build_alias_lookup(known_keys)
    icon_map = build_local_icon_map(icons_source)
    file_icon_map = build_file_icon_map(base)

    sortable = []
    unknown = 0
    for i, block in enumerate(entries):
        extinf = block[0]
        key = classify_key(extinf, known_keys, alias_lookup)
        logo = resolve_logo(extinf, key, icon_map, file_icon_map)
        if logo:
            block[0] = set_attr(extinf, "tvg-logo", logo)
        elif key is None:
            unknown += 1
        rank = order_index.get(key, 10**9 + i)
        sortable.append((rank, i, block))

    sortable.sort(key=lambda x: (x[0], x[1]))
    out_lines = (preamble if preamble else ["#EXTM3U"]).copy()
    for _, _, block in sortable:
        out_lines.extend(block)

    output_path.write_text("\n".join(out_lines) + "\n", encoding="utf-8")
    print(f"input_entries={len(entries)}")
    print(f"order_keys={len(order_index)}")
    print(f"order_file={order_file}")
    print(f"unknown_entries={unknown}")
    print(f"output_file={output_path}")


if __name__ == "__main__":
    main()
