import app from "flarum/forum/app";
import Modal from "flarum/common/components/Modal";
import type { IInternalModalAttrs } from "flarum/common/components/Modal";
import type Mithril from "mithril";
import Button from "flarum/common/components/Button";

export interface ConfirmModalAttrs extends IInternalModalAttrs {
  title?: Mithril.Children;
  content?: Mithril.Children;
  confirmLabel?: string;
  confirmClassName?: string;
  onConfirm?: () => Promise<unknown> | void;
}

export default class ConfirmModal extends Modal<ConfirmModalAttrs> {
  busy = false;

  className() {
    return "Modal--small ConfirmModal";
  }

  title(): Mithril.Children {
    return this.attrs.title;
  }

  content(): Mithril.Children {
    return (
      <div className="Modal-body">
        <div className="ConfirmModal-body">{this.attrs.content}</div>
        <div className="ConfirmModal-actions">
          <Button onclick={() => this.hide()} disabled={this.busy}>
            {app.translator.trans("ygpynet-giveaways.forum.form.cancel")}
          </Button>{" "}
          <Button
            className={this.attrs.confirmClassName || "Button Button--primary"}
            loading={this.busy}
            onclick={() => this.confirm()}
          >
            {this.attrs.confirmLabel ||
              app.translator.trans(
                "ygpynet-giveaways.forum.confirm_button",
              )}
          </Button>
        </div>
      </div>
    );
  }

  confirm() {
    const result = this.attrs.onConfirm?.();
    if (result && typeof (result as Promise<unknown>).then === "function") {
      this.busy = true;
      m.redraw();
      (result as Promise<unknown>)
        .then(() => {
          this.busy = false;
          this.hide();
        })
        .catch(() => {
          this.busy = false;
          m.redraw();
        });
    } else {
      this.hide();
    }
  }
}
