# AKSO Bridge and UEA Plugin
This plugin handles UEA.org's connection to AKSO and specific markdown rendering extensions.

This plugin is an extension for [Grav CMS](http://github.com/getgrav/grav).

## Building
```sh
./build.sh
```

## UEA Markdown extensions
### Figures
Figures can be used to add a caption to an image.

```md
[[figuro]]
![katido](katido.jpg)
jen katido
[[/figuro]]
```

Anything that is not an image will be put inside a figcaption.

Using the following syntax, full-width figures can be created:

```md
[[figuro !]]
![katido](katido.jpg)
jen katido
[[/figuro]]
```

Full-width figures should be used sparsely and only with wide-aspect images. They will span the entire width of the screen.

### Image Carousels
Image carousels are a special kind of figure.

```md
[[bildkaruselo]]
![katido](katido1.jpg)
## Katido
katido
![katido](katido2.jpg)
![katido](katido3.jpg)
[![linked carousel image](img.jpg)](/link)
[[/bildkaruselo]]
```

This will create a full-width figure that will automatically switch between the images if Javascript is available.

Any text below an image will be displayed as a caption for that page.

### Section Markers
```md
!### Katido
!###[some-id] Katido
```

Putting an exclamation mark before the three octothorpes creates an h3 that is a section marker and will look different.
You can add an optional id anchor.

### Info Boxes
```md
[[informskatolo]]
Katido
[[/informskatolo]]

[[anonceto]]
Kato
[[/anonceto]]
```

This will create an info box. While they *can* be nested, it is not recommended because it may be too narrow to read comfortably on phones.

### Expandables
Expandables can be created as follows:

```md
[[etendeblo]]
klaki tie ĉi por legi pri katidoj

---

katido
[[/etendeblo]]
```

Any text before the first horizontal rule will be displayed as a summary when collapsed.

### Lists
Lists can be created as follows:

```md
[[listo 3]]
```

The number following `listo` is the list ID.

### Congresses
Individual congress fields can be output inline:

```md
Ekzemplo: [[kongreso nomo 1/2]]
```

The syntax for congress fields is `[[kongreso FIELD ID]]`.
ID is either `1` for congress 1, or `1/2` for congress instance 2 in congress 1.

Following inline fields are supported:

| Field | Congresses | Instances | Description |
|:-|:-:|:-:|:-|
| nomo | yes | yes | Prints the name
| mallongigo | yes | | Prints the abbreviation
| homaID | | yes | Prints the human ID
| komenco | | yes | prints the start date
| fino | | yes | prints the end date

Additionally, following block fields are supported using the same syntax:

```md
[[kongreso aliĝintoj 1/2 show_name_field first_name_field]]
[[kongreso aliĝintoj 1/2 show_name_field first_name_field another_field more_fields]]
```

These will show a list of congress participants.

- `show_name_field` should refer to the name of a bool field. A name will only be shown if this value is true.
- `first_name_field` should refer to the name of a string field for the first name.
- Additional fields will be shown in a table

#### Countdown component
```md
[[kongreso tempokalkulo 1/2]]
```

This inline component will show a live countdown to the beginning of a congress.

```md
[[kongreso tempokalkulo! 1/2]]
```

This block component will show a large countdown to the beginning of a congress.

### Members-only content
```md
[[se membro]]
Kato
[[alie]]
[[nurmembroj]]
[[/se membro]]
```

The `[[se membro]]` block construct, with an optional `[[alie]]` clause, shows its content only to members.
Anything in the `[[alie]]` clause will be shown only to non-members.

The `[[nurmembroj]]` block construct shows an alert box saying the content is only for members and links to sign-up.

### Big Buttons
```md
[[butono /donaci Donaci]]
[[butono! /donaci Donaci]]
```

Will display a big centered button that links to the given url.
Add an exclamation mark to make it a primary button.

### Multiple Columns
```md
[[kolumnoj]]
Kato
===
kato
[[/kolumnoj]]
```

The `===` indicates a column break.

### Additional Extensions
Probably not commonly used; mostly for the home page.

- `[[aktuale /path 4 "title"]]`: shows a news carousel or sidebar depending on layout. The path should point to the blog page and the number indicates how many items to show.
- `[[revuoj /revuoj 1 2 3]]`: shows the given magazines (space-separated ids) given a magazines page at /revuoj
- `[[kongreso 1/2 /path/to/target optional_header_image.jpg]]`: shows the given congress instance
    - (upload the image to the same page)
    - use `[[kongreso tempokalkulo 1/2 /path/to/target img.jpg]]` to add a countdown

```md
[[intro]]
eo text
[[en]]
en text
[[de]]
...
[[/intro]]
```

shows the given intro text keyed by browser locale, falling back to esperanto
