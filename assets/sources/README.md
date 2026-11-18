# Source icons

Brand marks from [Simple Icons](https://github.com/simple-icons/simple-icons) (v16), each filled with the colour that
project records for the brand. Simple Icons is released under
[CC0 1.0 Universal](https://creativecommons.org/publicdomain/zero/1.0/) -- no rights reserved in the drawings. The
marks themselves remain the trademarks of their owners and are used here only to say which site or app a visit came
from; CC0 does not license a trademark, and nothing here suggests an endorsement.

`direct.svg`, `email.svg` and `website.svg` are this library's own, under its own licence, for a source with no mark.

The drawings match Simple Icons 16.38.0, except `openai.svg` and `slack.svg`: version 16 removed both marks, so these two are the version 15 drawings, kept so ChatGPT and Slack visits still have a picture.

Which source gets which file, and which colour, is `ArrayPress\ReferrerUtils\Icons` (`src/Icons.php`). To add one: put
the SVG here under a name of lower-case letters and digits, add the source's name -- exactly as the `Sources` or
`Campaigns` table spells it -- to `Icons::FILES` and `Icons::COLORS`, and run the suite, which checks every file is
used and every entry has a file.
