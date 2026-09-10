# Brand source files

These are the original exports of the Planvio mark and wordmark. **The application does not
load anything from this directory** — it uses the derived assets in
[`public/img/brand/`](../public/img/brand), which is what you should reference from code.

## A warning about the filenames

`Icon-Dark.svg` and `Icon-Light.svg` are **byte-identical**, and so are `Logo-Dark-H.svg`
and `Logo-Light-H.svg`. The export was misconfigured: every file here fills the mark with
`#f0efef`, which means all four are *dark-background* variants. Used on Planvio's light
theme the logo is very nearly invisible.

They are kept as-is because they are the originals, and rewriting a source export to hide a
mistake in it helps nobody.

## What the application actually uses

`public/img/brand/` holds the derived set. The important ones replace the `#f0efef` fill
with `currentColor`, so a single file renders correctly on both themes and follows the text
colour of whatever contains it:

| File | Fill | Use |
|---|---|---|
| `planvio-mark.svg` | `currentColor` | The mark, anywhere it inherits a text colour |
| `planvio-logo-h.svg` | `currentColor` | Horizontal wordmark, theme-adaptive |
| `planvio-logo-v.svg` | `currentColor` | Vertical wordmark, theme-adaptive |
| `planvio-mark-ink.svg` | `#0E1420` | Fixed dark, for light backgrounds that cannot inherit |
| `planvio-mark-inverse.svg` | `#FFFFFF` | Fixed light, for dark backgrounds |
| `planvio-logo-h-ink.svg` | `#0E1420` | Fixed dark wordmark — email, README light mode |
| `planvio-logo-h-inverse.svg` | `#FFFFFF` | Fixed light wordmark — README dark mode |

Contexts that cannot inherit a colour — email clients, the favicon, Open Graph images — need
a fixed-tone variant. Everything inside the application should use the `currentColor` ones.

## Colours

| | |
|---|---|
| Brand blue | `#3F66B0` |
| Ink | `#0E1420` |
| Paper | `#F0EFEF` |

The brand blue is the anchor of the entire palette: the Tailwind scale in
`resources/css/app.css` is generated so that `--color-brand-600` is exactly `#3F66B0`, and
every other step is derived from its hue and saturation. Change that one value and the whole
interface follows.

## Trademark

Planvio's code is licensed under the [AGPL-3.0](../LICENSE). The **name and the logo are
not** — they are trademarks of Hatem Sweileh.

You are welcome to fork Planvio; that is what the licence is for. Please give your fork its
own name and its own mark, so nobody is misled about who published what. Planvio's branding
settings let an installation set its own name, logo, favicon and accent colour without
touching a single file here.
