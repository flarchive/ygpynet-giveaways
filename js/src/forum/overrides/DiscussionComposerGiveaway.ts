// @ts-nocheck
import app from "flarum/forum/app";
import { extend } from "flarum/common/extend";
import Button from "flarum/common/components/Button";

import GiveawayFormModal from "../components/GiveawayFormModal";
import { listGiveaways, showGiveaway } from "../../common/api";

let cachedSlug: string | null = null;

function detectGiveawaySlug(dc): string | null {
  try {
    const content = dc.composer?.fields?.content?.() || "";
    const match = (typeof content === "string" ? content : "").match(/\[giveaway slug=([^\s\]]+)/);
    if (match) {
      cachedSlug = match[1];
      return match[1];
    }
  } catch (e) {}

  if (cachedSlug) return cachedSlug;
  return null;
}

export default function init() {
  extend(
    "flarum/forum/components/DiscussionComposer",
    "headerItems",
    function (items) {
      const dc = this;
      const slug = detectGiveawaySlug(dc);

      items.add(
        "giveaway",
        Button.component(
          {
            className: "DiscussionComposer-changeTags Button Button--ua-reset",
            onclick: () => {
              if (slug) {
                showGiveaway(slug).then(({ data: giveaway }) => {
                  app.modal.show(GiveawayFormModal, {
                    giveaway,
                    onsave: () => {},
                  });
                });
              } else {
                listGiveaways().then(({ data: before }) => {
                  const slugs = before.map((g) => g.slug);

                  app.modal.show(GiveawayFormModal, {
                    onsave: () => {
                      listGiveaways().then(({ data: after }) => {
                        const g =
                          after.find((x) => !slugs.includes(x.slug)) ||
                          after.find((x) => x.status === "active") ||
                          after[0];
                        if (!g) return;

                        cachedSlug = g.slug;
                        const link = `[giveaway slug=${g.slug}]`;
                        const editor = dc.composer?.editor;

                        if (
                          editor &&
                          typeof editor.insertAtCursor === "function"
                        ) {
                          editor.insertAtCursor(link);
                        } else {
                          const cur = dc.composer.fields.content();
                          dc.composer.fields.content(
                            cur
                              ? cur.replace(/\s+$/, "") + "\n\n" + link.trim()
                              : link.trim(),
                          );
                        }
                        m.redraw();
                      });
                    },
                  });
                });
              }
            },
          },
          m(
            "span.TagLabel.untagged",
            slug
              ? (app.translator.trans("ernestdefoe-giveaways.forum.edit_composer_button") as string)
              : (app.translator.trans("ernestdefoe-giveaways.forum.composer_button") as string),
          ),
        ),
        5,
      );
    },
  );
}
