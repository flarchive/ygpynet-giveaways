<?php

namespace ErnestDefoe\Giveaways\Formatter;

use ErnestDefoe\Giveaways\Giveaway;
use ErnestDefoe\Giveaways\GiveawayEntry;
use Flarum\Foundation\Config;
use Flarum\Http\RequestUtil;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;
use Psr\Http\Message\ServerRequestInterface;
use s9e\TextFormatter\Renderer;

class GiveawayCardRender
{
    public function __construct(
        protected UrlGenerator $url,
        protected TranslatorInterface $translator,
        protected Config $config
    ) {
    }

    public function __invoke(
        Renderer $renderer,
        mixed $context,
        string $xml,
        ?ServerRequestInterface $request = null
    ): string {
        if (! preg_match_all('#<GIVEAWAY slug="([^"]+)"(?:/>|>.*?</GIVEAWAY>)#s', $xml, $m)) {
            return $xml;
        }

        // 当前浏览者（actor）：与 /giveaways 页面一致，从请求里取，而非帖子的作者。
        $actor = $request ? RequestUtil::getActor($request) : null;

        $giveaways = Giveaway::query()
            ->whereIn('slug', $m[1])
            ->with('category')
            ->get()
            ->keyBy('slug');

        return preg_replace_callback(
            '#<GIVEAWAY slug="([^"]+)"(?:/>|>.*?</GIVEAWAY>)#s',
            function (array $match) use ($giveaways, $actor): string {
                $g = $giveaways->get($match[1]);

                return $g ? $this->card($g, $actor) : '';
            },
            $xml
        );
    }

    protected function card(Giveaway $g, ?User $actor): string
    {
        $category = $g->category;

        // 浏览者自己的条目数（与页面卡片 GiveawayPresenter::present 的 myEntries 一致）
        $myEntries = 0;
        if ($actor && ! $actor->isGuest()) {
            $myEntries = (int) GiveawayEntry::query()
                ->where('giveaway_id', $g->id)
                ->where('user_id', $actor->id)
                ->value('entries');
        }

        return '<GIVEAWAY slug="'.$this->xml($g->slug).'"'
            .' cover="'.$this->xml((string) $g->cover_url).'"'
            .' status="'.$this->xml($g->status).'"'
            .' statuslabel="'.$this->xml($this->translator->trans('ernestdefoe-giveaways.forum.status_'.$g->status)).'"'
            .' category="'.($category ? $this->xml($category->name) : '').'"'
            .' categorycolor="'.($category ? $this->xml($category->color) : '').'"'
            .' categoryicon="'.($category ? $this->xml((string) $category->icon) : '').'"'
            .' title="'.$this->xml($g->title).'"'
            .' prize="'.$this->xml($g->prize).'"'
            .' endsin_iso="'.$this->xml($g->ends_at->toIso8601String()).'"'
            .' endsin="'.$this->xml($g->ends_at->setTimezone($this->config['app.timezone'])->format('Y-m-d H:i')).'"'
            .' entrants="'.(int) $g->entries()->count().'"'
            .' entrantslabel="'.$this->xml($this->translator->trans('ernestdefoe-giveaways.forum.entrants_label')).'"'
            .' myentries="'.$myEntries.'"'
            .' myentrieslabel="'.$this->xml(
                $myEntries > 0
                    ? $this->translator->trans('ernestdefoe-giveaways.forum.your_entries', ['count' => $myEntries])
                    : ''
            ).'"'
            .'/>';
    }

    protected function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}