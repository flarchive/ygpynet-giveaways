<?php

namespace ErnestDefoe\Giveaways\Formatter;

use s9e\TextFormatter\Configurator;

class GiveawayCardConfigure
{
    public function __invoke(Configurator $config): void
    {
        $config->BBCodes->addCustom(
            '[giveaway slug={TEXT} cover={URL?} categorycolor={COLOR?}]',
            <<<'XML'
<a class="GiveawayCard" href="/giveaways/{@slug}" style="margin:20px">
  <xsl:choose>
    <xsl:when test="@cover != ''">
        <div class="GiveawayCard-cover" style="background-image: url('{@cover}');">
          <span class="GiveawayCard-status GiveawayCard-status--{@status}">{@statuslabel}</span>
        </div>
      </xsl:when>
    <xsl:otherwise>
    <div class="GiveawayCard-cover GiveawayCard-cover--placeholder">
      <i class="icon fas fa-gift" aria-hidden="true"></i>
      <span class="GiveawayCard-status GiveawayCard-status--{@status}">{@statuslabel}</span>
    </div>
     </xsl:otherwise>
  </xsl:choose>
  <div class="GiveawayCard-body">
    <xsl:if test="@category != ''">
      <span class="GiveawayCard-category" style="color: {@categorycolor}">
        <xsl:if test="@categoryicon != ''">
          <i class="icon {@categoryicon}" aria-hidden="true"></i>
        </xsl:if>
        {@category}
      </span>
    </xsl:if>
    <h3 class="GiveawayCard-title">{@title}</h3>
    <div class="GiveawayCard-prize"><i class="icon fas fa-trophy" aria-hidden="true"></i> {@prize}</div>
    <div class="GiveawayCard-meta">
      <span><i class="icon fas fa-clock" aria-hidden="true"></i> <time class="GiveawayCard-endsin" datetime="{@endsin_iso}">{@endsin}</time></span>
      <span><i class="icon fas fa-users" aria-hidden="true"></i> {@entrants} {@entrantslabel}</span>
    </div>
  </div>
</a>
XML
        );

        $config->tags['GIVEAWAY']->attributes['slug']->required = true;
        $config->tags['GIVEAWAY']->rules->breakParagraph();
    }
}