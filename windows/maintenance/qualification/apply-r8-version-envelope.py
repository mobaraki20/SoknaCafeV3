from pathlib import Path

root = Path(__file__).resolve().parents[3]
path = root / 'windows/maintenance/src/Sokna.Maintenance.UpdateEngine/Program.cs'
text = path.read_text(encoding='utf-8-sig')
old_count = text.count('0.3.4')
if old_count < 3:
    raise SystemExit(f'expected multiple 0.3.4 engine envelope markers, found {old_count}')
text = text.replace('0.3.4', '0.3.5')
text = text.replace('R7 durable Local adoption bridge', 'R8 sibling-release Local adoption bridge')
if '0.3.4' in text:
    raise SystemExit('stale 0.3.4 marker remains')
if 'R7 durable Local adoption bridge' in text:
    raise SystemExit('stale R7 help marker remains')
if '0.3.5' not in text or 'R8 sibling-release Local adoption bridge' not in text:
    raise SystemExit('R8 envelope markers missing after rewrite')
path.write_text(text, encoding='utf-8')
print(f'R8 Update Engine envelope bump applied ({old_count} semantic markers)')
