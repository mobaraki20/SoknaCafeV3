import pathlib,sys
css=pathlib.Path(sys.argv[1]).read_text()
assert '.sc-print-preview-stage .sc-print-preview--image{' in css
section=css.split('.sc-print-preview-stage .sc-print-preview--image{',1)[1].split('}',1)[0]
assert 'inline-size:min(100%,340px)' in section
assert 'block-size:auto' in section
assert 'max-block-size:none' in section
stage=css.split('.sc-print-preview-stage{')[-1].split('}',1)[0]
assert 'overflow:auto' in stage
print('PASS IMAGE_PREVIEW_PAPER_WIDTH_AND_SCROLL_CSS')
