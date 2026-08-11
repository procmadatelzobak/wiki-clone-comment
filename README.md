# WikiCloneComment

A MediaWiki extension that lets a page borrow its body from another wiki — typically
Wikipedia — and surround it with clearly-marked commentary of your own.

The point is not mirroring. It is that on subjects an upstream encyclopedia already
covers well, rewriting the article is wasted effort, while *annotating* it is not. This
extension keeps the copy current and keeps your voice visibly separate from the
source's.

```
┌─ Attribution ───────────────────────────────────────────────┐
│ Copied from "Ludwig von Mises" on Wikipedie (revision       │
│ 26077859, synced 12 August 2026). CC BY-SA 4.0 · authors    │
└─────────────────────────────────────────────────────────────┘

  Ludwig Heinrich Edler von Mises was an Austrian economist…

  ══ Life ══════════════════════════════════════════════════
  ┏━ COMMENTARY — YOUR WIKI ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┓
  ┃ What the article omits about his time in Geneva is…     ┃
  ┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┛
  Mises was born in Lemberg in 1881…
```

## How a cloned page looks in wikitext

The upstream text is never part of the page source. The page holds the directive and
the commentary, nothing else:

```wikitext
{{#wikiclone: https://cs.wikipedia.org/wiki/Ludwig_von_Mises}}

<wikiclone-comment>
Shown above the article. Use this for framing the piece as a whole.
</wikiclone-comment>

<wikiclone-comment anchor="Život_v_Evropě">
Shown directly under the "Život v Evropě" heading.
</wikiclone-comment>
```

The directive accepts either a full upstream URL — which is what people naturally
paste — or a bare page title.

Because the body lives elsewhere, "the article text cannot be edited locally" needs no
lock: there is nothing in the page source to edit. The extension only enforces the
other half of that rule, that free prose may not be mixed in beside the comments,
where it would render outside the marked boxes.

## Why rendered HTML and not wikitext

Copying wikitext looks like the natural choice — it is the format both wikis store —
and it is a trap. Measured against the Czech Wikipedia in August 2026:

| Article | Wikitext | Transitive dependencies |
|---|---|---|
| Ludwig von Mises | 28 kB | **77 pages** — 52 templates + 25 Lua modules |
| Anarchokapitalismus | 27 kB | **67 pages** — 46 templates + 21 Lua modules |

Those dependencies include `Modul:Wikidata`, so the infobox does not render at all
without a Wikibase client installed. Importing them means adopting a permanent
synchronisation problem an order of magnitude larger than the articles themselves.

Fetching the rendered HTML is one request per article, and the upstream wiki maintains
its own templates. The price is a sanitisation and link-rewriting pass, which is code
written once rather than maintenance owed forever.

## Where the copy is stored

In an ordinary wiki page in a dedicated namespace, holding a JSON envelope:

```json
{
  "source": "cs.wikipedia.org",
  "title": "Ludwig von Mises",
  "revid": 26077859,
  "fetched": "2026-08-12T03:30:11Z",
  "sections": [ { "anchor": "Životopis", "level": 2, "line": "Životopis" } ],
  "html": "…"
}
```

A page rather than a custom table, because a page already has the things a table would
have had to reimplement: it is included in the wiki's database backup, it has a
revision history that doubles as a log of what changed upstream, it can be read in a
browser when something looks wrong, and it needs no schema migration.

## Anchoring, and what happens when an anchor dies

A comment is pinned to a heading's anchor — the `id` MediaWiki generates, which the
upstream API hands us directly in its `sections` list, so anchors are never guessed.

Source articles get reorganised. When a heading a comment was pinned to disappears,
the comment is **not** dropped and **not** silently reattached to a neighbour. It is
rendered at the end of the page, labelled with the anchor it used to point at, and the
page is added to a tracking category so the loss is visible rather than accumulating
unnoticed. The sync log names the vanished headings as they go.

## Installation

Requires MediaWiki 1.43 or later.

```bash
cd extensions
git clone https://github.com/procmadatelzobak/wiki-clone-comment.git WikiCloneComment
```

```php
wfLoadExtension( 'WikiCloneComment' );

// Required. Wikimedia's User-Agent policy asks for a way to reach the operator;
// syncing refuses to run while this is empty.
$wgWikiCloneContact = 'admin@example.org';
```

No database schema is added, so `update.php` is not needed.

## Configuration

| Setting | Default | Meaning |
|---|---|---|
| `$wgWikiCloneSources` | `cs.wikipedia.org` | Allow-list of upstream wikis. Nothing outside it can be fetched. |
| `$wgWikiCloneContact` | `''` | Contact address embedded in the outgoing `User-Agent`. Required. |
| `$wgWikiCloneDefaultSource` | `null` | Host assumed when the directive gives a bare title. `null` = the only configured source. |
| `$wgWikiCloneNamespaceName` | `null` | Display name for the shadow namespace. `null` keeps `WikiClone`. |
| `$wgWikiCloneSyncUser` | `WikiClone sync` | System account that owns shadow-page edits. |
| `$wgWikiCloneStripClasses` | edit links, `noprint`, `navbox`, `navbox2`, `metadata`, `ambox` | Classes dropped from the fetched article. |
| `$wgWikiCloneRequestTimeout` | `25` | Per-request timeout, seconds. |
| `$wgWikiCloneMaxLag` | `5` | `maxlag` API parameter — politeness towards Wikimedia wikis. |

Adding a second source:

```php
$wgWikiCloneSources['en.wikipedia.org'] = [
	'api'        => 'https://en.wikipedia.org/w/api.php',
	'articleUrl' => 'https://en.wikipedia.org/wiki/$1',
	'siteName'   => 'Wikipedia',
	'licence'    => 'CC BY-SA 4.0',
	'licenceUrl' => 'https://creativecommons.org/licenses/by-sa/4.0/',
];
$wgWikiCloneDefaultSource = 'cs.wikipedia.org';
```

## Syncing

```bash
php extensions/WikiCloneComment/maintenance/syncWikiClone.php --all
```

Cloned pages find themselves: the directive records the page in `page_props` as a side
effect of rendering, and the script reads that. There is no registry to maintain.

The script asks the upstream API for current revision ids in batches of 50 and
downloads only the articles whose revision actually moved, so a daily run over a few
hundred clones is a handful of requests on a quiet day.

| Flag | Effect |
|---|---|
| `--all` | Every cloned page (the default). |
| `--page=TITLE` | One upstream article. |
| `--force` | Download even when the revision is unchanged. |
| `--max=N` | Stop after N downloads. |
| `--dry-run` | Report, write nothing. |

Daily, via systemd:

```ini
# wikiclone-sync.timer
[Timer]
OnCalendar=*-*-* 03:30:00
RandomizedDelaySec=1800
```

The randomised delay matters when syncing from Wikimedia: it keeps every installation
of this extension from arriving at the same second.

## Reusing content: what the licence requires

Wikipedia text is CC BY-SA. Reusing it obliges you to name the source, credit the
authors, and mark what you changed. The attribution banner does all three — it links
the article, links the page history (the accepted way of crediting every author), names
the exact revision copied, and states the licence.

Two things are your responsibility, not the extension's:

- **Keep the commentary visually distinct.** The default styling is deliberately loud.
  Toning it down until readers cannot tell the two apart undermines both the licence
  position and the reader's ability to trust either voice. The separation is the point.
- **Images carry their own licences.** They are hotlinked from the upstream media
  repository, not copied, and their terms are frequently *not* the article's terms. If
  you later choose to host them yourself, each one needs its own attribution.

## Security

- **Allow-list, not deny-list.** Upstream HTML is parsed into a DOM and rebuilt from a
  fixed list of permitted elements and attributes. `style` is filtered through
  MediaWiki's own CSS sanitiser; `javascript:` and `data:` URLs are dropped; event
  handlers cannot survive because no attribute outside the list does.
- **Sanitised on the way in.** The cleaned HTML is what gets stored, so a later change
  to the rendering path cannot resurrect markup that was unsafe on arrival.
- **No SSRF.** An editor can paste a URL, but the host is checked against
  `$wgWikiCloneSources` before any request is made. Nothing an editor writes can steer
  a request at an arbitrary address, internal ones included.

## Known limitations

- **The local table of contents does not list cloned headings.** The article is spliced
  in after parsing, so the parser never sees those headings.
- **Templates that style themselves render unstyled.** TemplateStyles arrive as inline
  `<style>` blocks, which the sanitiser drops. The extension supplies layout for the
  constructs that matter (infoboxes, floated thumbnails) and removes the ones that are
  noise on a copy — navigation and authority-control boxes, via
  `$wgWikiCloneStripClasses`. A wiki whose upstream uses different class names for
  those will need to say so there.
- **Search indexing is noisy.** The shadow page is indexed with its HTML markup.
  Cloned articles are findable, but the index entry contains tag noise.
- **Deleted upstream images leave a gap**, since images are referenced rather than
  copied.
- **One clone per page.** A second directive on the same page is an error.
- **Duplicate content**, in the search-engine sense, is inherent to the idea. The value
  a cloned page adds is the commentary, not the copy.

## About this project

This extension was designed and written by **Claude**, an AI system made by Anthropic,
working under human direction. The architecture — rendered HTML over wikitext, shadow
pages over a custom table, anchors taken from the API rather than computed — came out
of that design process and is documented above along with the measurements behind each
decision.

It is published in the hope that it is useful, and with no promise of support. Issues
and pull requests are welcome; response is not guaranteed.

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).
