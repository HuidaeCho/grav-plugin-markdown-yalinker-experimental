<?php
namespace Grav\Plugin;

use Grav\Common\Plugin;
use RocketTheme\Toolbox\Event\Event;

class MarkdownYalinkerExperimentalPlugin extends Plugin
{
    public static function getSubscribedEvents()
    {
        return [
            'onMarkdownInitialized' => ['onMarkdownInitialized', 0],
            'onPageInitialized' => ['onPageInitialized', 0]
        ];
    }

    public function onMarkdownInitialized(Event $event)
    {
        $markdown = $event['markdown'];

        $markdown->addInlineType('[', 'Yalink');
        $markdown->inlineYalink = function($excerpt) {
            if (strpos($excerpt['text'], '[[') === 0 && strpos($excerpt['text'], ']]') !== false && preg_match('/^\[\[.*?\]\]/', $excerpt['text'], $matches)) {
                $extent = strlen($matches[0]);
                $link = $this->parseYalink($matches[0]);

                return [
                    'extent' => $extent,
                    'element' => [
                        'name' => 'a',
                        'text' => $link['text'],
                        'attributes' => [
                            'href' => $link['href'],
                        ],
                    ],
                ];
            }
        };
    }

    // Write Markdown links
    //
    // XXX: This feature *tries* to rewrite YALinks as Markdown links. The idea
    // is to convert YALinks to regular Markdown links at the time of writing
    // Markdown files for compatibility with Markdown parsers. It is HIGHLY
    // experimental and can break inline/block code syntax in the Markdown
    // file! It may be removed entirely in the future if I cannot reliably
    // implmene it. You have to manually enable this feature by adding the
    // following line:
    //
    // write_markdown_links: true
    //
    // in user/config/plugins/markdown-yalinker-experimental.yaml
    public function onPageInitialized()
    {
        $this->write_markdown_links = $this->config->get('plugins.markdown-yalinker-experimental.write_markdown_links');
        if (!$this->write_markdown_links)
            return;

        $page = $this->grav['page'];
        $content = $page->rawMarkdown();

        if (!preg_match('/\[\[.*?\]\]/', $content))
            return;

        $lines = preg_split('/\r\n|\r|\n/', $content);
        $asis = false;
        $rewritten = false;

        foreach ($lines as &$line) {
            if (preg_match('/^`{3,}$/', $line))
                $asis = !$asis;

            if ($asis)
                continue;

            if (!preg_match_all('/\[\[.*?\]\]/', $line, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE))
                continue;

            $rewritten = true;

            // reverse $matches to avoid indexing problems
            foreach (array_reverse($matches) as $match) {
                $yalink = $match[0][0];
                $offset = $match[0][1];
                $link = $this->parseYalink($yalink);
                $markdown_link = sprintf('[%s](%s)', $link['text'], $link['href']);
                $line = substr_replace($line, $markdown_link, $offset, strlen($yalink));
            }
        }

        if (!$rewritten)
            return;

        $content = implode(PHP_EOL, $lines);
        $page->rawMarkdown($content);
        $page->save();
    }

    private function parseYalink($yalink)
    {
        // syntax: [[(link)?(|(text)?)?]]
        // * [[]] => <a href="/current">Current Title</a>
        // * [[|]] => <a href="/current">/current</a>
        // * [[.|]] => <a href="/current">.</a>
        // * [[page]] => <a href="/current/page">page</a>
        // * [[page|]] => <a href="/current/page">page</a>
        // * [[page|text]] => <a href="/current/page">text</a>
        // * [[../page]] => <a href="/page">page</a>
        // * [[../page|]] => <a href="/page">../page</a>
        // * [[../page|text]] => <a href="/page">text</a>
        // * [[/folder/slashes//in page title]] => <a href="/folder/slashes-in-page-title">slashes/in page title</a>
        // * [[/folder/slashes//in page title|]] => <a href="/folder/slashes-in-page-title">/folder/slashes/in page title</a>
        // * [[/folder/slashes//in page title|text]] => <a href="/folder/slashes-in-page-title">text</a>
        // * [[url]] => <a href="url">url</a>
        // * [[mailto:...]] => <a href="mailto:...">mailto:...</a>
        // * [[mail:...]] => <a href="mailto:...">...</a>

        // must always be true
        if (!preg_match('/^\[\[(.*?)(?:\|(.+?))?\]\]$/', $yalink, $matches))
            return;

        $href = $matches[1];
        $has_text = isset($matches[2]);

        $show_path = false;
        if ($has_text)
            $text = $matches[2];
        else {
            $len = strlen($href);
            if ($len && $href[$len - 1] == '|') {
                $href = substr($href, 0, $len - 1);
                $show_path = true;
            }
            $text = $href;
        }

        // if url
        if (preg_match('/^(?:https?:\/\/|mail(?:to)?:)/', $href))
            $text = preg_replace('/\|$/', '', $text);
        else {
        // if page
            // escape non-folder slashes
            if (strpos($href, '//') !== false) {
                $href = preg_replace('/\/{2,}/', '-', $href);
                if (!$has_text)
                    // for now, // => \x00
                    $text = preg_replace('/\/{2,}/', '\x00', $text);
            }

            $current_uri = $this->grav['uri'];
            $rootUrl = $current_uri->rootUrl(); // /grav
            $current_route = $current_uri->route(); // /current-page
            $current_title = $this->grav['page']->title(); // Current Page Title

            // pre-clean up path in href
            if (strpos($href, '...') !== false)
                // a/...../b => a/../b
                $href = preg_replace('/\.{3,}/', '..', $href);
            if (strpos($href, '/./') !== false)
                // a/./././b => a/b
                $href = preg_replace('/(?:\/\.)+\//', '/', $href);
            if (strpos($href, './') === 0)
                // ./a => a
                $href = substr($href, 2);

            $path_prefix = $current_route;
            if (preg_match('/^([\/.]*)\/(.*)$/', $href, $matches)) {
                if (strlen($matches[1]) && $matches[1][0] != '/')
                    // relative path
                    $path_prefix .= $matches[1];
                else
                    // absolute path
                    $path_prefix = '';
                $href = $matches[2];
            }

            switch ($href) {
            case '':
                if (!$has_text)
                    $text = $show_path ? $path_prefix : $current_title;
                break;
            case '.':
                $href = '';
                break;
            }

            // add back path prefix that may has been removed by the slug() function
            $href = $path_prefix.($href ? '/'.self::slug($href) : '');

            // post-clean up path in href
            $paths = explode('/', $href);
            $npaths = count($paths);
            for ($i = 0; $i < $npaths; $i++) {
                if ($paths[$i] == '..')
                    $paths[$i - 1] = $paths[$i] = '';
            }
            $href = join('/', $paths);
            $href = $rootUrl.preg_replace('/\/\/+/', '/', $href);

            if (!$has_text) {
                // handle page path in text
                if (!$show_path && preg_match('/^(?:[\/.]*\/)?(?:[^\/]+\/)*(.*)$/', $text, $matches))
                    // show page title only if requested (no | at the end)
                    $text = $matches[1];

                // convert escaped non-folder slashes back to slashes
                $text = str_replace('\x00', '/', $text);
            }
        }

        // if mail, hide mailto from text
        if (strpos($href, 'mail:') === 0) {
            $href = substr_replace($href, 'mailto:', 0, 5);
            if (!$has_text)
                $text = substr_replace($text, '', 0, 5);
        }

        return [
            'href' => $href,
            'text' => $text
        ];
    }

    // Adopted from the Admin plugin (user/plugins/admin/classes/utils.php)
    private static function slug(string $str)
    {
        if (function_exists('transliterator_transliterate')) {
            $str = transliterator_transliterate('Any-Latin; NFD; [:Nonspacing Mark:] Remove; NFC;', $str);
        } else {
            $str = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str);
        }

        $str = strtolower($str);
        $str = preg_replace('/[\s.]+/', '-', $str);
        // leave slashes as is
        $str = preg_replace('/[^a-z0-9\/-]/', '', $str);
        // page./.slug => page/slug
        $str = preg_replace('/-?\/-?/', '/', $str);
        $str = trim($str, '-');

        return $str;
    }
}
