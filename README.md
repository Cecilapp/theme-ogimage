# Open Graph image component theme

The _Open Graph image_ component theme for [Cecil](https://cecil.app) generates the image displayed when a page is shared (social networks, messaging apps): the title of the page, its author and the title of the site, drawn on a background image.

![Generated Open Graph image](docs/screenshot.png)

**[Demo](https://cecilapp.github.io/theme-ogimage/)**

## Features

- An image for each page (`og.png`, 1200 × 628), published next to the page
- An image for the site (title and description), used by the homepage
- Nothing generated for a page (or a site) which already has an `image`
- Title wrapped on several lines, with a font size adapted to its length
- Custom background, fonts, text color and padding
- Multilingual: texts and options of each language
- Images are cached: they are only generated again when a text or an option changes

## Requirements

- Cecil 9.25 or later, with [plugins](https://cecil.app/documentation/developers/extend/#plugins) support: the `og_image()` function is registered by the `OgImage\Plugin` plugin
- PHP GD extension with FreeType support (without it, a warning is logged and no image is generated)

## Installation

Allow the theme installer plugin in the `composer.json` file of your Cecil project (required since Composer 2.2):

```json
{
  "config": {
    "allow-plugins": {
      "cecil/theme-installer": true
    }
  }
}
```

Then require the theme:

```bash
composer require cecil/theme-ogimage
```

> Or [download the latest archive](https://github.com/Cecilapp/theme-ogimage/releases/latest/) and uncompress its content in `themes/ogimage`.

## Usage

Add `ogimage` in the `theme` section of your configuration file (`cecil.yml`):

```yaml
theme:
  - ogimage
```

Then give the generated image to the [metatags partial](https://cecil.app/documentation/configuration/site/#metatags) in the `<head>` of your base layout, with the `image` variable:

```twig
{{ include('partials/metatags.html.twig', {page, site, image: og_image(page)}, with_context = false) }}
```

### The `og_image()` function

`og_image(page)` returns the image as an [asset](https://cecil.app/documentation/assets/#asset), or `null` when there is nothing to generate: the metatags partial then uses the `image` of the page or of the site, as usual.

| Page                                                        | Image                                                         |
|-------------------------------------------------------------|---------------------------------------------------------------|
| With an `image` in its front matter                         | None (`null`): the image of the page is used                  |
| With `ogimage: false` in its front matter                   | None (`null`)                                                 |
| Homepage, and pages out of the [`sections`](#configuration) | Image of the site, or none if the site has an `image`         |
| Other pages                                                 | Image of the page: its title, its author and the site title   |

The site title of the images of the pages can be hidden with the `site_title` option (e.g. on a personal website, where the site title is the name of the author).

Without argument, `og_image()` returns the image of the site.

The author is the `author` of the page, otherwise the one of the site (a name, or a map with a `name` key).

As the function returns an asset, the image can also be displayed in a page:

```twig
{% set image = og_image(page) %}
{% if image %}
<img src="{{ url(image) }}" width="{{ image.width }}" height="{{ image.height }}" alt="{{ page.title|e('html_attr') }}">
{% endif %}
```

### Configuration

Default options, registered by the plugin:

```yaml
ogimage:
  enabled: true                      # `false` to disable the generation
  sections: []                       # sections of the pages with their own image (all pages if empty)
  background: ogimage/background.png # background image (asset path), resized to 1200 × 628
  color: '#111111'                   # text color (hexadecimal)
  fonts:                             # TrueType fonts (asset paths)
    regular: ogimage/fonts/GoogleSansCode-Regular.ttf
    bold: ogimage/fonts/GoogleSansCode-Bold.ttf
  padding:                           # space around the text, in pixels
    top: 105
    right: 110
    bottom: 100
    left: 110
  by: by                             # text before the name of the author
  site_title: true                   # `false` to hide the site title from the images of the pages
```

For example, to generate an image for blog posts only, with your own background:

```yaml
ogimage:
  sections: [blog]
  background: images/og-background.png
  padding:
    left: 80
```

The background and the fonts are [assets](https://cecil.app/documentation/assets/): set a path in the `assets` (or `static`) directory of your site, or override the default files by creating a file with the same path (e.g. `assets/ogimage/background.png`).

### Internationalization

The title and the description of the site, and the options of the theme, are those of the language of the page: translate the text before the author in the configuration of the language.

```yaml
languages:
  - code: fr
    name: Français
    locale: fr_FR
    config:
      ogimage:
        by: par
```

The images of a language are published in its directory (e.g. `/fr/blog/post/og.png`).

### Use it in a theme

A visual theme can require this component theme, so its users have nothing to configure (Cecil 9.29 or later). Add it to the `require` section of the `composer.json` of the theme:

```json
{
  "require": {
    "cecil/theme-ogimage": "^1.0"
  }
}
```

Then require it in the `config.yml` of the theme, with the options of the theme (background, fonts, colors…):

```yaml
theme:
  - ogimage
ogimage:
  background: <theme>/og-background.png
```

## Development

Run the demo website with the theme under development:

```bash
php demo/link.php
php cecil.phar serve demo
```

## License

_Open Graph image_ is a free software distributed under the terms of the MIT license.

The default fonts, [Google Sans Code](https://github.com/googlefonts/googlesans-code), are distributed under the terms of the [SIL Open Font License](assets/ogimage/fonts/OFL.txt).
