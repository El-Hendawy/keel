"""MkDocs hook: links from the docs to files outside docs/ (examples, source, README) point to GitHub."""

import posixpath
import re

REPO = "https://github.com/El-Hendawy/keel/blob/main/"
LINK = re.compile(r"\]\((?!https?://|#|mailto:)([^)\s]+)\)")


def on_page_markdown(markdown, page, config, files):
    base = posixpath.dirname("docs/" + page.file.src_uri)

    def rewrite(match):
        target = match.group(1)
        path, _, anchor = target.partition("#")
        resolved = posixpath.normpath(posixpath.join(base, path))
        published = resolved.startswith("docs/") and not resolved.startswith(("docs/rfcs/", "docs/hooks/"))
        if published or path == "":
            return match.group(0)
        return "](" + REPO + resolved + ("#" + anchor if anchor else "") + ")"

    return LINK.sub(rewrite, markdown)
