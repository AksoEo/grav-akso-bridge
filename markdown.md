# Markdown Extensions
### Text and Layout
#### Section Markers
```md
!### Katido
!###[some-id] Katido
```

Putting an exclamation mark before the three octothorpes creates an h3 that is a section marker and will look different.
You can add an optional id anchor.

#### Info Boxes
```md
[[informskatolo]]
Katido
[[/informskatolo]]

[[anonceto]]
Kato
[[/anonceto]]
```

This will create an info box. While they *can* be nested, it is not recommended because it may be too narrow to read comfortably on phones.

#### Expandables
Expandables can be created as follows:

```md
[[etendeblo]]
klaki tie ĉi por legi pri katidoj

---

katido
[[/etendeblo]]
```

Any text before the first horizontal rule will be displayed as a summary when collapsed.

#### Big Buttons
```md
[[butono /donaci Donaci]]
[[butono! /donaci Donaci]]
[[butono!! /donaci Donaci]]
```

Will display a big centered button that links to the given url.
Add an exclamation mark to make it a primary button.
Add another mark to make it even bigger.

### Multiple Columns
```md
[[kolumnoj]]
Kato
===
kato
[[/kolumnoj]]
```

The `===` indicates a column break.

### Flags
```md
[[flago:nl]] NL flag
[[flago:epo]] Esperanto flag
```

### Tables
```md
[[tabelo]]
a | b
c | d
[[/tabelo]]
```

Creates a table that allows HTML inside. (The table does not have a header.)

### Images and Figures
#### Figures
Figures can be used to add a caption to an image.

```md
[[figuro]]
![katido](katido.jpg)
jen katido
[[/figuro]]
```

Anything that is not an image will be put inside a figcaption.

Using the following syntax, full-bleed figures can be created:

```md
[[figuro !]]
![katido](katido.jpg)
jen katido
[[/figuro]]
```

Full-bleed figures should be used sparsely and only with wide-aspect images. They will span the entire width of the screen.

#### Image Carousels
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

### AKSO Objects
#### Lists
Lists can be created as follows:

```md
[[listo 3]]
```

The number following `listo` is the list ID.

#### Congresses
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
[[kongreso aliĝintoj 1/2 show_name_var first_name_var]]
[[kongreso aliĝintoj 1/2 show_name_var first_name_var another_var "Title Label" yet_another_var "Label"]]
```

These will show a list of congress participants.

- `show_name_var` should refer to the name of a bool AKSO script variable. A name will only be shown if this value is true.
- `first_name_var` should refer to the name of a string AKSO script variable for the first name.
- Additional variables will be shown in a table

Variables may also refer to form vars directly (e.g. `@first_name`)

#### Countdown component
```md
[[kongreso tempokalkulo 1/2]]
```

This inline component will show a live countdown to the beginning of a congress.

```md
[[kongreso tempokalkulo! 1/2]]
```

This block component will show a large countdown to the beginning of a congress.

#### Members-only content
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

#### Logged in-only content
```md
[[se ensalutinta]]
kato
[[alie]]
[[nurensalutintoj]]
[[/se ensalutinta]]
```

Same as members-only, but with the condition being that the user is logged in users.

#### Intermediaries
```md
[[perantoj]]
```

Shows intermediaries.

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
