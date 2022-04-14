# remark-new-lines

Preserves new line characters in paragraph tags, for [remark](https://github.com/remarkjs/remark)

## Installation

```
npm install remark-new-lines
```

## Usage

```js
const unified = require('unified')
const markdown = require('remark-parse')
const remark2rehype = require('remark-rehype')
const html = require('rehype-stringify')

const newlines = require('remark-new-lines')

const value = 'This is a \n\nparagraph.\n\n\nwith new lines\n\n\n\npreservation'

unified()
  .use(markdown)
  .use(newlines, { offset: 1 }) // the offset will respect double spacing
  .use(remark2rehype)
  .use(html)
  .process(value, (err, file) => {
    console.log(String(file))
  })
```

*Example Input*

```
This is a
paragraph

with new lines


preservation
```

*Example Output*

```html
<p>This is a</p>
<p>paragraph</p>
<br/>
<p>with new lines></p>
<br/>
<br/>
<p>preservation</p>
```

## License

MIT
# remark-new-lines

Preserves new line characters in paragraph tags, for [remark](https://github.com/remarkjs/remark)

## Installation

```
npm install remark-new-lines
```

## Usage

```js
const unified = require('unified')
const markdown = require('remark-parse')
const remark2rehype = require('remark-rehype')
const html = require('rehype-stringify')

const newlines = require('remark-new-lines')

const value = 'This is a \n\nparagraph.\n\n\nwith new lines\n\n\n\npreservation'

unified()
  .use(markdown)
  .use(newlines, { offset: 1 }) // the offset will respect double spacing
  .use(remark2rehype)
  .use(html)
  .process(value, (err, file) => {
    console.log(String(file))
  })
```

*Example Input*

```
This is a
paragraph

with new lines


preservation
```

*Example Output*

```html
<p>This is a</p>
<p>paragraph</p>
<br/>
<p>with new lines></p>
<br/>
<br/>
<p>preservation</p>
```

## License

MIT
