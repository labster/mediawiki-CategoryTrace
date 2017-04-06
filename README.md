# mediawiki-CategoryTrails
A Mediawiki extension to: Navigate through categories alphabetically from the Category box on each page

( Note that this repo is not actually functional yet, but this will
eventually be an extension. )

This extension is inspired by a PmWiki feature,
[WikiTrails](http://www.pmwiki.org/wiki/PmWiki/WikiTrails),
and a similar feature at TV Tropes Wiki simply called "Indexes".

This applies the principle of navigating through similar pages to Mediawiki
categories. Unlike those features Pmwiki features, you can't really specify
the order of the navigation here (consider a Scribunto module or Template for
more complex navigation).  Instead, we are automatically generating
navigation inside a category, using its category sortkey or
{{DEFAULTSORTKEY}}.
