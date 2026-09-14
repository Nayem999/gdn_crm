<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The guides, rendered as a page anybody can read without signing in.
 *
 * Public on purpose: the first thing a new user needs is the instructions, and
 * an instruction manual behind a login is no use to somebody who cannot work
 * out how to log in. It reads the same two markdown files the repository ships,
 * so there is one copy of the documentation and it cannot drift from what the
 * suite checks in DocumentationTest.
 *
 * Nothing here touches the database or the session, and nothing in the files is
 * per-installation: a reader sees the manual, never anybody's data.
 */
class ShowGuide extends Controller
{
    /**
     * The guides, in the order they are shown.
     *
     * The key prefixes every heading id, because both files open with "## 1."
     * and two identical ids would make the contents list point at whichever
     * came first.
     *
     * @var array<string, array{file: string, title: string, summary: string}>
     */
    private const GUIDES = [
        'user' => [
            'file' => 'docs/USER_GUIDE.md',
            'title' => 'User Guide',
            'summary' => 'Working in the CRM day to day.',
        ],
        'admin' => [
            'file' => 'docs/ADMIN_GUIDE.md',
            'title' => 'Administrator Guide',
            'summary' => 'Installing, configuring and running it.',
        ],
    ];

    public function __invoke(): View
    {
        $sections = [];

        foreach (self::GUIDES as $key => $guide) {
            $path = base_path($guide['file']);

            if (! is_file($path)) {
                // The page is the documentation; half of it missing is a
                // deployment that did not ship the docs directory, not a page
                // worth rendering with a hole in it.
                throw new NotFoundHttpException('The documentation has not been installed.');
            }

            [$html, $contents] = $this->render((string) file_get_contents($path), $key);

            $sections[] = [
                'key' => $key,
                'title' => $guide['title'],
                'summary' => $guide['summary'],
                'html' => $html,
                'contents' => $contents,
            ];
        }

        return view('docs.guide', ['sections' => $sections]);
    }

    /**
     * Markdown to HTML, with an id on every heading and a contents list built
     * from the same pass.
     *
     * `html_input => strip` even though these files are ours: they are read off
     * disk at request time on a public route, and a page that renders whatever
     * HTML happens to be in a file is one bad deployment away from being an
     * injection point.
     *
     * @return array{0: string, 1: array<int, array{id: string, title: string}>}
     */
    private function render(string $markdown, string $prefix): array
    {
        $html = Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        $contents = [];

        // The first h1 is the guide's own title, which the page already shows.
        $html = (string) preg_replace('/<h1>.*?<\/h1>/s', '', $html, 1);

        $html = (string) preg_replace_callback(
            '/<h([23])>(.*?)<\/h\1>/s',
            function (array $match) use ($prefix, &$contents): string {
                $text = trim(html_entity_decode(strip_tags($match[2])));
                $id = $prefix.'-'.Str::slug($text);

                if ($match[1] === '2') {
                    $contents[] = ['id' => $id, 'title' => $text];
                }

                return '<h'.$match[1].' id="'.e($id).'">'.$match[2].'</h'.$match[1].'>';
            },
            $html
        );

        // Relative links between the two files land on the same page here.
        $html = str_replace(
            ['href="ADMIN_GUIDE.md"', 'href="USER_GUIDE.md"'],
            ['href="#admin"', 'href="#user"'],
            $html
        );

        // The guides lean on tables, which are the first thing to push a phone
        // sideways. Each one gets its own horizontal scroller — there is no
        // element in generated markdown to hang that on otherwise.
        $html = str_replace(
            ['<table>', '</table>'],
            ['<div class="table-scroll"><table>', '</table></div>'],
            $html
        );

        return [$html, $contents];
    }
}
