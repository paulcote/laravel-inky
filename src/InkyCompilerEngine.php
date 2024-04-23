<?php

namespace Rsvpify\LaravelInky;

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\DomCrawler\Crawler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Compilers\CompilerInterface;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;

class InkyCompilerEngine extends CompilerEngine
{
    protected $filesystem;

    public function __construct(CompilerInterface $compiler, Filesystem $filesystem)
    {
        parent::__construct($compiler);

        $this->filesystem = $filesystem;
    }

    public function get($inkyFilePath, array $data = [])
    {
        $html = parent::get($inkyFilePath, $data);

        preg_match_all('/((?:<link\b.+?href=")(?!http)([^"]*?)(?:".*?>))/', $html, $stylesheetsMatches);
        $html = preg_replace('/((?:<link\b.+?href=")(?!http)(?:[^"]*?)(?:".*?>))/', '', $html);

        $stylesheetsMatchesFiles = $stylesheetsMatches[2];
        $stylesheets = array_merge($stylesheetsMatchesFiles, config('inky.stylesheets'));

        $combinedStyles = collect($stylesheets)->map(function ($path) {
            return $this->filesystem->get(base_path($path));
        })->implode("\n\n");

        $mediaQueries = $this->extractMediaQueries($combinedStyles);

        $html = str_replace(config('inky.style_replace_tag'), count($mediaQueries) > 0 ? '<style>'.implode("\n\n", $mediaQueries).'</style>' : '', $html);

        $crawler = new Crawler;
        $crawler->addHtmlContent($html);
        $cssLinks = $crawler->filter('link[rel=stylesheet]');

        $cssLinks->each(function (Crawler $crawler) {
            foreach ($crawler as $node) {
                $node->parentNode->removeChild($node);
            }
        });

        $htmlWithoutLinks = $crawler->html();

        $inliner = new CssToInlineStyles;
        
        return $inliner->convert($htmlWithoutLinks, $combinedStyles);
    }

    public function getFiles()
    {
        return $this->filesystem;
    }

    private function extractMediaQueries($css)
    {
        $mediaBlocks = array();
    
        $start = 0;
        while (($start = strpos($css, "@media", $start)) !== false)
        {
            $s = array();
            $i = strpos($css, "{", $start);
            if ($i !== false)
            {
                array_push($s, $css[$i]);
                $i++;
                while (!empty($s))
                {
                    if ($css[$i] == "{")
                    {
                        array_push($s, "{");
                    }
                    elseif ($css[$i] == "}")
                    {
                        array_pop($s);
                    }
                    $i++;
                }
                $mediaBlocks[] = substr($css, $start, ($i + 1) - $start);
                $start = $i;
            }
        }
    
        return $mediaBlocks;
    }
}
