import app from "flarum/forum/app";
import { extend } from "flarum/common/extend";
import IndexSidebar from "flarum/forum/components/IndexSidebar";
import LinkButton from "flarum/common/components/LinkButton";

import GiveawaysPage from "./pages/GiveawaysPage";
import GiveawayPage from "./pages/GiveawayPage";
import GiveawayWonNotification from "./components/GiveawayWonNotification";
import GiveawayClaimedNotification from "./components/GiveawayClaimedNotification";
import Giveaway from "../common/models/Giveaway";
import initComposerGiveaway from "./overrides/DiscussionComposerGiveaway";
import Discussion from "flarum/common/models/Discussion";
import Badge from "flarum/common/components/Badge";

initComposerGiveaway();

extend(Discussion.prototype, "badges", function (badges: any) {
  if (this.attribute("hasGiveaway")) {
    badges.add(
      "giveaway",
      Badge.component({
        type: "giveaway",
        icon: "fas fa-gift",
        label: app.translator.trans(
          "ernestdefoe-giveaways.forum.giveaway_badge_tooltip",
        ),
        tabindex: "0",
      }),
      5,
    );
  }
});

app.initializers.add("ernestdefoe-giveaways", () => {
  app.store.models.giveaways = Giveaway;

  app.routes["giveaways.index"] = {
    path: "/giveaways",
    component: GiveawaysPage,
  };
  app.routes["giveaways.show"] = {
    path: "/giveaways/:slug",
    component: GiveawayPage,
  };

  app.notificationComponents.giveawayWon = GiveawayWonNotification;
  app.notificationComponents.giveawayClaimed = GiveawayClaimedNotification;

  // Nav link sits with "All Discussions" in the sidebar navigation. Flarum 2
  // exposes these via IndexSidebar.navItems (IndexPage no longer owns the nav).
  extend(IndexSidebar.prototype, "navItems", function (items: any) {
    if (app.forum.attribute("giveawaysShowNav") === false) return;

    const label =
      app.forum.attribute<string>("giveawaysNavLabel") ||
      app.translator.trans("ernestdefoe-giveaways.forum.nav");

    items.add(
      "giveaways",
      LinkButton.component(
        {
          href: app.route("giveaways.index"),
          icon: "fa-solid fa-gift",
          className: "Badge--giveaway",
        },
        label,
      ),
      5,
    );
  });
});

document.addEventListener("click", (e) => {
  if (e.defaultPrevented) return; // 已有 Mithril Link 处理的卡片跳过
  const card = (e.target as Element | null)?.closest?.("a.GiveawayCard");
  if (!card) return;
  if (e.button !== 0 || e.shiftKey || e.ctrlKey || e.metaKey || e.altKey)
    return;
  const href = card.getAttribute("href");
  if (!href) return;
  e.preventDefault();
  m.route.set(href);
});
