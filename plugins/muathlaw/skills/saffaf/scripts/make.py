#!/usr/bin/env python3
"""One-shot pipeline: input.md -> OUT.docx (for Word) + OUT.pdf (via LibreOffice), with auto TOC.
usage: python3 make.py input.md OUTPUT_DIR/NAME [--uthmani]   (writes NAME.docx, NAME.pdf and the fonts zip)
--uthmani: set the verses between ﴿ ﴾ in Uthmani script — only when the user asked for it."""
import os, sys, re, json, shutil, subprocess, tempfile, zipfile, unicodedata
HERE = os.path.dirname(os.path.abspath(__file__))
# claude.ai ships a sandbox-safe LibreOffice wrapper with its docx skill; elsewhere call soffice directly
SOFFICE = '/mnt/skills/public/docx/scripts/office/soffice.py'
SOFFICE_CMD = ['python3', SOFFICE] if os.path.exists(SOFFICE) else [shutil.which('soffice') or shutil.which('libreoffice') or 'soffice']
PROFILE = '/tmp/ar_typeset_lo'

def setup():
    # Linux: fontconfig reads ~/.fonts. Elsewhere (macOS): headless LibreOffice ignores the system font
    # folders and only sees its bundled fonts plus <profile>/user/fonts, so the fonts go there.
    fd = os.path.expanduser('~/.fonts') if sys.platform.startswith('linux') else PROFILE + '/user/fonts'
    os.makedirs(fd, exist_ok=True)
    for f in os.listdir(os.path.join(HERE, '..', 'assets', 'fonts')):
        shutil.copy(os.path.join(HERE, '..', 'assets', 'fonts', f), fd)
    if UTHMANI: shutil.copy(os.path.join(HERE, '..', 'assets', 'quran', 'UthmanicHafs_V22.ttf'), fd)
    if shutil.which('fc-cache'): subprocess.run(['fc-cache', '-f'], capture_output=True)
    os.makedirs(PROFILE + '/user', exist_ok=True)
    open(PROFILE + '/user/registrymodifications.xcu', 'w').write(
        '<?xml version="1.0" encoding="UTF-8"?><oor:items xmlns:oor="http://openoffice.org/2001/registry" xmlns:xs="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        '<item oor:path="/org.openoffice.Office.Common/I18N/CTL"><prop oor:name="CTLTextNumerals" oor:op="fuse"><value>3</value></prop></item>'
        '<item oor:path="/org.openoffice.Office.Common/I18N/CTL"><prop oor:name="CTLFont" oor:op="fuse"><value>true</value></prop></item></oor:items>')

def lo(args):
    subprocess.run(SOFFICE_CMD + [f'-env:UserInstallation=file://{PROFILE}', '--headless'] + args, capture_output=True)

def to_pdf(docx, pdf):
    d = tempfile.mkdtemp()
    lo(['--convert-to', 'odt', '--outdir', d, docx])
    odt = os.path.join(d, os.path.basename(docx)[:-5] + '.odt')
    zin = zipfile.ZipFile(odt); tmp = odt + '.tmp'; zout = zipfile.ZipFile(tmp, 'w')
    for it in zin.infolist():
        data = zin.read(it.filename)
        if it.filename in ('styles.xml', 'content.xml'):
            t = data.decode()
            def fix(m):
                tag = m.group(0)
                if 'endnote' in tag: return tag
                tag = re.sub(r'\s(style:num-prefix|style:num-suffix)="[^"]*"', '', tag)
                # the number inside the note sits on the line, not raised
                tag = re.sub(r'text:citation-style-name="[^"]*"', 'text:citation-style-name="Note_20_Label"', tag)
                return tag.replace('<text:notes-configuration', '<text:notes-configuration style:num-prefix="(" style:num-suffix=")"', 1)
            t = re.sub(r'<text:notes-configuration[^>]*>', fix, t)
            # LibreOffice drops the tab after the note's own number when it reads the Word file, so the text of the
            # first line runs straight on from «(1)»; give it back (once) so the text starts where its later lines hang.
            # The tab goes inside the first span: the number takes the look of what begins the note (a Latin note's
            # number stays Western, in its Latin font).
            t = re.sub(r'<text:note-body><text:p\b[^>]*>(?:<text:span\b[^>]*>)?(?:<text:tab/>)?',
                       lambda m: m.group(0) if m.group(0).endswith('<text:tab/>') else m.group(0) + '<text:tab/>', t)
            if it.filename == 'styles.xml':
                t = t.replace('</office:styles>', '<style:style style:name="Note_20_Label" style:display-name="Note Label" style:family="text"/></office:styles>', 1)
            data = t.encode()
        zout.writestr(it, data, compress_type=zipfile.ZIP_STORED if it.filename == 'mimetype' else zipfile.ZIP_DEFLATED)
    zout.close(); shutil.move(tmp, odt)
    lo(['--convert-to', 'pdf', '--outdir', d, odt])
    shutil.copy(odt[:-4] + '.pdf', pdf); shutil.rmtree(d)

DIG = str.maketrans('\u0660\u0661\u0662\u0663\u0664\u0665\u0666\u0667\u0668\u0669\u06F0\u06F1\u06F2\u06F3\u06F4\u06F5\u06F6\u06F7\u06F8\u06F9', '01234567890123456789')
def norm(s):
    # letters + digits (so headings that differ only by number stay distinct); digit runs sorted because RTL extraction may reverse them
    s = unicodedata.normalize('NFKC', s).translate(DIG)
    s = re.sub(r'[^\u0621-\u064A0-9]', '', s)
    return re.sub(r'[0-9]+', lambda m: ''.join(sorted(m.group(0))), s)

def headings(md):
    out = []
    for ln in open(md, encoding='utf-8'):
        ln = ln.rstrip('\n')
        if ln.startswith('## '): out.append((ln[3:], 2))
        elif ln.startswith('# '): out.append((ln[2:], 1))
        elif ln.strip() == '@toc': out.append(('فهرس الموضوعات', 1))
    return [(re.sub(r'\*\*|\[\^[^\]]+\]', '', t).strip(), l) for t, l in out]

def find_pages(pdf, heads):
    import pdfplumber
    pages = []
    with pdfplumber.open(pdf) as doc:
        texts = []; is_toc = []
        for p in doc.pages:
            bold = ''.join(c['text'] for c in sorted(p.chars, key=lambda c: (round(c['top']), -c['x0'])) if 'Bold' in c['fontname'])
            texts.append(norm(bold))
            # TOC pages carry dot leaders; their bold entries must not count as the headings themselves
            is_toc.append('..........' in (p.extract_text() or ''))
    start = 0
    for t, l in heads:
        key = norm(t)[:30]; pg = None
        self_toc = (t == 'فهرس الموضوعات')
        for i in range(start, len(texts)):
            if is_toc[i] and not self_toc: continue
            if key and key in texts[i]: pg = i + 1; start = i; break
        pages.append(pg)
    return pages

def keep_heads(md):
    """The ## and ### headings in order — the ones that must stay with the paragraph after them."""
    return [re.sub(r'\*\*|\[\^[^\]]+\]', '', ln.split(' ', 1)[1]).strip() for ln in open(md, encoding='utf-8').read().split('\n') if ln.startswith(('## ', '### '))]

def lone_heads(pdf, heads):
    """Indexes of the headings left alone at the foot of a page. LibreOffice keeps a heading with the next paragraph,
    except when that paragraph carries a footnote that no longer fits: it then moves the paragraph and leaves the heading."""
    import pdfplumber
    out = []
    with pdfplumber.open(pdf) as doc:
        pages = []
        for p in doc.pages:
            rows = {}
            for c in p.chars: rows.setdefault(round(c['top']), []).append(c)
            pages.append(sorted(rows.items()))
    start = 0
    for n, t in enumerate(heads):
        key = norm(t)[:30]
        for i in range(start, len(pages)):
            at = next((top for top, cs in pages[i] if key and key in norm(''.join(c['text'] for c in sorted(cs, key=lambda c: -c['x0']) if 'Bold' in c['fontname']))), None)
            if at is None: continue
            start = i
            # body lines under the heading: footnotes are set smaller, and the last line of the page is its number
            if not any(top > at + 2 and max(c['size'] for c in cs) >= 13.9 for top, cs in pages[i][:-1]): out.append(n)
            break
    return out

BREAKS = []
def lo_pdf(ts, md, docx, pdf, args):
    """The PDF route: build, then give every heading left alone at the foot of a page a page break before it and rebuild."""
    heads = keep_heads(md)
    for _ in range(6):
        subprocess.run(['python3', ts, md, docx, 'lo'] + args + ([f'--break-before={",".join(map(str, BREAKS))}'] if BREAKS else []), check=True)
        to_pdf(docx, pdf)
        new = [n for n in lone_heads(pdf, heads) if n not in BREAKS]
        if not new: break
        BREAKS.extend(new)

FONTS_ZIP = 'خطوط-لملف-Word.zip'
def fonts_zip(docx):
    """One zip beside the Word file with the fonts it needs: the two Lotus Lotfi files always, and the Quran font
    whenever the document has at least one verse set in Uthmani script (typeset.py embeds that font only then)."""
    adir = os.path.join(HERE, '..', 'assets')
    files = [os.path.join(adir, 'fonts', n) for n in sorted(os.listdir(os.path.join(adir, 'fonts')))]
    if 'word/fonts/font1.odttf' in zipfile.ZipFile(docx).namelist(): files.append(os.path.join(adir, 'quran', 'UthmanicHafs_V22.ttf'))
    path = os.path.join(os.path.dirname(os.path.abspath(docx)), FONTS_ZIP)
    with zipfile.ZipFile(path, 'w', zipfile.ZIP_DEFLATED) as z:
        for f in files: z.write(f, os.path.basename(f))
    return path

HINDI = str.maketrans('0123456789', '٠١٢٣٤٥٦٧٨٩')
if __name__ == '__main__':
    UTHMANI = ['--uthmani'] if '--uthmani' in sys.argv else []
    md, out = [a for a in sys.argv[1:] if a != '--uthmani'][:2]
    os.makedirs(os.path.dirname(os.path.abspath(out)), exist_ok=True)
    setup()
    w = tempfile.mkdtemp(); ts = os.path.join(HERE, 'typeset.py')
    has_toc = any(l.strip() == '@toc' for l in open(md, encoding='utf-8'))
    toc_path = os.path.join(w, 'toc.json')
    if has_toc:
        heads = headings(md)
        json.dump([[t, '٠٠', l] for t, l in heads], open(toc_path, 'w'), ensure_ascii=False)
        for _ in range(2):  # converge page numbers
            lo_pdf(ts, md, w + '/p.docx', w + '/p.pdf', [toc_path] + UTHMANI)
            pg = find_pages(w + '/p.pdf', heads)
            json.dump([[t, str(p).translate(HINDI) if p else '؟', l] for (t, l), p in zip(heads, pg)], open(toc_path, 'w'), ensure_ascii=False)
        missing = [t for (t, l), p in zip(heads, pg) if not p]
        if missing: print('TOC: page not found for:', missing)
    args = ([toc_path] if has_toc else []) + UTHMANI
    subprocess.run(['python3', ts, md, out + '.docx', 'word'] + args, check=True)
    lo_pdf(ts, md, w + '/f.docx', out + '.pdf', args)
    # the fonts go beside the output in one zip: Word shows the document in them only where they are installed
    print('done:', out + '.docx', out + '.pdf', '| fonts:', fonts_zip(out + '.docx'))
