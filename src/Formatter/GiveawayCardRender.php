<?php

namespace ErnestDefoe\Giveaways\Formatter;

use ErnestDefoe\Giveaways\Giveaway;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use s9e\TextFormatter\Renderer;

class GiveawayCardRender
{
    public function __construct(
        protected UrlGenerator $url,
        protected TranslatorInterface $translator
    ) {
    }

    public function __invoke(Renderer $renderer, mixed $context, string $xml): string
    {
        if (! preg_match_all('#<GIVEAWAY slug="([^"]+)"(?:/>|>.*?</GIVEAWAY>)#s', $xml, $m)) {
            return $xml;
        }

        $giveaways = Giveaway::query()
            ->whereIn('slug', $m[1])
            ->with('category')
            ->get()
            ->keyBy('slug');

        return preg_replace_callback(
            '#<GIVEAWAY slug="([^"]+)"(?:/>|>.*?</GIVEAWAY>)#s',
            function (array $match) use ($giveaways): string {
                $g = $giveaways->get($match[1]);

                return $g ? $this->card($g) : '';
            },
            $xml
        );
    }

    protected function card(Giveaway $g): string
    {
        $category = $g->category;

        return '<GIVEAWAY slug="'.$this->xml($g->slug).'"'
            .' cover="'.$this->xml((string) $g->cover_url).'"'
            .' status="'.$this->xml($g->status).'"'
            .' statuslabel="'.$this->xml($this->translator->trans('ernestdefoe-giveaways.forum.status_'.$g->status)).'"'
            .' category="'.($category ? $this->xml($category->name) : '').'"'
            .' categorycolor="'.($category ? $this->xml($category->color) : '').'"'
            .' categoryicon="'.($category ? $this->xml((string) $category->icon) : '').'"'
            .' title="'.$this->xml($g->title).'"'
            .' prize="'.$this->xml($g->prize).'"'
            .' endsin="'.$this->xml($g->ends_at->format('Y-m-d H:i')).'"'
            .' entrants="'.(int) $g->entries()->count().'"'
            .'/>';
    }

    protected function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}