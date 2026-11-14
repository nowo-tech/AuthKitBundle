# Provider brand marks

The social / SSO buttons of the login page carry the **official brand mark** of the provider (1.48). Google, Microsoft
and GitHub all require their own mark on a sign-in button, with rules about its colour, its proportions and the space
around it, and a Google OAuth verification review can be failed over a text-only button.

## Table of contents

- [What the bundle renders](#what-the-bundle-renders)
- [Marks shipped by the bundle](#marks-shipped-by-the-bundle)
- [Trademark position of each mark](#trademark-position-of-each-mark)
- [Apple (and any other provider)](#apple-and-any-other-provider)
- [Replacing or removing a mark](#replacing-or-removing-a-mark)
- [How the markup respects the guidelines](#how-the-markup-respects-the-guidelines)

## What the bundle renders

A host configures nothing. With an enabled `google` credential the default login template renders:

```html
<a class="btn-secondary nowo-auth-kit-providers__button" href="/login/social/google" data-nowo-auth-kit-provider="google">
    <img class="nowo-auth-kit-providers__mark nowo-auth-kit-providers__mark--on-white"
         src="/bundles/nowoauthkit/img/social/google.svg" width="18" height="18" alt="" aria-hidden="true">
    <span class="nowo-auth-kit-providers__label">Continue with Google</span>
</a>
```

The marks are shipped as ordinary bundle assets, so `php bin/console assets:install` (Flex runs it on `composer
install`) is all that is needed — the same step the bundle's CSS already requires. The bundle registers its own asset
package (`nowo_auth_kit` → `/bundles/nowoauthkit`), so no `framework.assets` entry is needed either.

## Marks shipped by the bundle

| Provider | File | Source fetched from | Form |
|----------|------|---------------------|------|
| `google` | `src/Resources/public/img/social/google.svg` | `https://www.gstatic.com/firebasejs/ui/2.0.0/images/auth/google.svg` (Google-hosted, `logo_googleg_48dp`, carries Google's copyright notice) | the standard four-colour Google "G", unmodified |
| `microsoft` | `src/Resources/public/img/social/microsoft.svg` | `https://learn.microsoft.com/en-us/entra/identity-platform/media/howto-add-branding-in-apps/ms-symbollockup_mssymbol_19.svg` (the asset Microsoft's own "Add branding" page offers for apps) | the four-square Microsoft symbol in its fixed colours, unmodified |
| `github` | `src/Resources/public/img/social/github.svg` | `https://raw.githubusercontent.com/primer/octicons/main/icons/mark-github-16.svg` | GitHub's Invertocat mark in black, unmodified |

Every file is byte-for-byte what the source serves. They are **not** re-drawn, re-coloured or re-exported.

`google.svg` carries Google's own copyright comment. `assets:install` copies the file byte for byte, so the default
deployment keeps it; if your build pipeline runs an SVG optimiser (SVGO and friends) over `public/bundles/`, exclude
`img/social/` or configure it to keep comments, so the notice is not stripped out of the shipped asset.

### Why Google's current asset pack is not the file shipped

Google's [branding guidelines](https://developers.google.com/identity/branding-guidelines) link a pre-approved asset
pack (`signin-assets.zip`). In its current form that pack contains **whole buttons only** (no standalone "G"), and the
"G" in them is the gradient refresh, drawn as a Figma export: an SVG `mask` plus a `foreignObject` holding a `<div>`
with a CSS `conic-gradient`. That construction cannot be used here:

- referenced from `<img>` (what keeps a mark un-recolourable) browsers do not render `foreignObject`, and the paths
  under it inherit `fill="none"` — the "G" comes out **blank**;
- inlined in the page it depends on inline `style` attributes, which a host with a strict `style-src` CSP (no
  `unsafe-inline`) drops — again a blank "G".

So the bundle ships Google's standard four-colour "G", which is plain `<path fill="#…">` artwork: it renders in `<img>`,
it renders under any CSP, and it cannot be re-coloured by host CSS. If a reviewer asks for the gradient mark, a host can
point `social_login.provider_marks.google` at its own copy of whichever file it prefers (see below).

## Trademark position of each mark

**This is a trademark question, not a copyright licence, and it is not legal advice.** What the sources say, read on
2026-11-13:

- **Google** — the branding guidelines require the mark on the button ("The button must always include the standard
  color for the Google 'G'", "you can't change the size or color of the Google 'G' logo", "Don't use monochrome
  versions"), publish pre-approved assets "for all platforms", and permit localised button text. They grant no explicit
  redistribution licence: "Use of Google brands in ways not expressly covered by this document is not allowed without
  prior written consent from Google." The bundle's position: shipping the unmodified mark so that each installation
  renders a compliant **Sign in with Google** button is the use the document expressly covers, performed by the
  application the document addresses. No attribution text is required by the guidelines; Google's copyright notice is
  kept inside the SVG.
- **Microsoft** — the identity-platform branding page provides the official images and says to use them in your app
  ("To download the official images for use in your app …", "DON'T alter the Microsoft logo"). Microsoft's general
  trademark guidelines are stricter ("our logos … can never be used without an express license"). The bundle's
  position: the identity-platform page is the permission for this specific use. Microsoft asks for a trademark footnote
  where its marks are used: *Microsoft is a trademark of the Microsoft group of companies.*
- **GitHub** — GitHub's brand guidelines list "Show integration: Use a permitted GitHub logo to inform others that your
  project integrates with GitHub" as a permitted use, forbid modification ("Do not modify the permitted GitHub logos,
  including changing the color, dimensions, or combining with other words or design elements"), restrict the colour to
  white, black (and in few cases grey or green), and otherwise require written permission. The `octicons` repository is
  MIT for code and points logo use back at those guidelines. The bundle's position: a **Continue with GitHub** button is
  that permitted "show integration" use, with the mark in black and unmodified. GitHub asks for a notice: *GITHUB®, the
  GITHUB® logo design and the INVERTOCAT logo design are trademarks of GitHub, Inc.*

None of the three marks is covered by the bundle's MIT licence: each stays the property of its owner and is included
only to render that provider's own sign-in button. A host that would rather not redistribute one of them can remove it
in one line (below), and the button then renders as text only.

## Apple (and any other provider)

**No Apple mark is shipped.** Apple's guidelines for third parties are explicit: "You may not use the Apple Logo or any
other Apple-owned graphic symbol, logo, or icon on or in connection with web sites, products, packaging, manuals,
promotional/advertising materials, or for any other purpose except pursuant to an express written trademark license from
Apple". The bundle has no such licence, so it ships no Apple artwork — and `apple` is not a built-in provider here in
any case. A host that configures an `apple` provider obtains the asset under its own Apple Developer agreement and
points `provider_marks.apple` at it; until then that button renders as text only.

The same holds for every other provider: no entry, no mark.

## Replacing or removing a mark

```yaml
nowo_auth_kit:
    profiles:
        default:
            social_login:
                provider_marks:
                    github: false                     # no mark: text-only button
                    apple: 'images/apple-black.svg'   # host asset, host default package
                    google:                           # full form
                        path: 'images/google-g.svg'
                        package: ~                    # ~ = the host's default asset package
                        on_white: true                # white backdrop + clear space
```

- Keys are provider keys, matched case-insensitively.
- `false` (or an empty `path`) removes the mark, including a built-in one.
- A bare string is the asset path.
- `on_white` is not required when replacing a built-in mark: it keeps that provider's default (on for the three built-in
  ones), so swapping only the file keeps a compliant Google button.

## How the markup respects the guidelines

- **Not re-coloured, not distorted** — the mark is an `<img>`, so no host CSS rule (`fill`, `color`, `filter`) can reach
  inside it. It sits in a fixed square box with `object-fit: contain`, so it keeps its aspect ratio whatever the box.
- **Clear space** — `gap` between mark and label, plus the padding of the white backdrop around the mark.
- **On white** — `--on-white` gives the mark a white, slightly rounded backdrop, so Google's "G" is on white however
  the host themes the button.
- **Accessible label intact** — the mark is decorative: `alt=""` and `aria-hidden="true"`. The accessible name of the
  button stays the translated label (*Continue with Google* / *Continuar con Google*), so a screen reader announces one
  link, not an image.
