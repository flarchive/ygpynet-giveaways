import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import type Mithril from 'mithril';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';

import { listGiveaways, listCategories } from '../../common/api';
import type { Giveaway, GiveawayCategory } from '../../common/api';
import GiveawayCard from '../components/GiveawayCard';
import GiveawayFormModal from '../components/GiveawayFormModal';
import CategoryManagerModal from '../components/CategoryManagerModal';

type StatusFilter = '' | 'open' | 'ended' | 'draft';

export default class GiveawaysPage extends Page {
  loading = true;
  loadingMore = false;
  giveaways: Giveaway[] = [];
  categories: GiveawayCategory[] = [];
  canCreate = false;
  canManage = false;
  status: StatusFilter = '';
  category: number | null = null;
  page = 1;
  hasMore = false;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    app.history?.push(
      'giveaways.index',
      app.translator.trans('ygpynet-giveaways.forum.page_title') as string,
      m.route.get(),
    );
    app.setTitle(app.translator.trans('ygpynet-giveaways.forum.page_title') as string);
    this.readFilters();
    this.load();
    this.loadCategories();
  }

  onupdate(vnode: Mithril.Vnode) {
    super.onupdate(vnode);
    // Back/forward or an external link can change the query without remounting
    // the page — re-read the filters and reload when they differ.
    const prev = `${this.status}|${this.category}`;
    this.readFilters();
    if (`${this.status}|${this.category}` !== prev) {
      this.load();
    }
  }

  /** Parse filter[status] / filter[category] from the current URL query. */
  readFilters() {
    const qs = (m.route.get() || '').split('?')[1] || '';
    const parsed: any = m.parseQueryString(qs) || {};
    const status = parsed?.filter?.status;
    const category = parsed?.filter?.category;
    this.status = ['open', 'ended', 'draft'].includes(status) ? status : '';
    this.category = category ? parseInt(String(category), 10) || null : null;
  }

  /** Push a filter change to the URL so it is shareable and survives reload. */
  setFilter(next: { status?: StatusFilter; category?: number | null }) {
    const status = next.status !== undefined ? next.status : this.status;
    const category = next.category !== undefined ? next.category : this.category;

    const query: Record<string, string> = {};
    if (status) query['filter[status]'] = status;
    if (category) query['filter[category]'] = String(category);

    const base = app.route('giveaways.index');
    const url = Object.keys(query).length ? `${base}?${m.buildQueryString(query)}` : base;

    if (m.route.get() === url) return;
    m.route.set(url, {}, { replace: true });
    // Same-route navigation does not remount; onupdate reloads, but nudge a
    // redraw so the pills react instantly.
    m.redraw();
  }

  load(reset = true) {
    this.loading = true;
    if (reset) {
      this.page = 1;
      this.giveaways = [];
    }
    listGiveaways(this.page, { status: this.status, category: this.category })
      .then((res) => {
        const data = res.data || [];
        this.giveaways = reset ? data : this.giveaways.concat(data);
        this.canCreate = !!(res.meta && res.meta.canCreate);
        this.canManage = !!(res.meta && res.meta.canManage);
        this.hasMore = !!(res.meta && res.meta.hasMore);
        this.loading = false;
        this.loadingMore = false;
        m.redraw();
      })
      .catch(() => {
        this.loading = false;
        this.loadingMore = false;
        m.redraw();
      });
  }

  loadMore() {
    if (this.loadingMore || this.loading) return;
    this.loadingMore = true;
    this.page += 1;
    this.load(false);
  }

  loadCategories() {
    listCategories().then((res) => {
      this.categories = res.data || [];
      m.redraw();
    });
  }

  create() {
    app.modal.show(GiveawayFormModal, { categories: this.categories, onsave: () => this.load() });
  }

  manageCategories() {
    app.modal.show(CategoryManagerModal, { onchange: () => this.loadCategories() });
  }

  tabs(): { id: StatusFilter; label: string }[] {
    const t = (k: string) => app.translator.trans('ygpynet-giveaways.forum.filters.' + k) as string;
    const tabs: { id: StatusFilter; label: string }[] = [
      { id: '', label: t('all') },
      { id: 'open', label: t('open') },
      { id: 'ended', label: t('ended') },
    ];
    if (this.canCreate || this.canManage) {
      tabs.push({ id: 'draft', label: t('draft') });
    }
    return tabs;
  }

  view(): Mithril.Children {
    const drafts = this.giveaways.filter((g) => g.status === 'draft');
    const active = this.giveaways.filter((g) => g.status === 'active');
    const past = this.giveaways.filter((g) => g.status === 'drawn' || g.status === 'cancelled');
    const t = (k: string) => app.translator.trans('ygpynet-giveaways.forum.' + k);

    const grid = (items: Giveaway[]) => (
      <div className="GiveawaysPage-grid">
        {items.map((g) => (
          <GiveawayCard key={g.id} giveaway={g} />
        ))}
      </div>
    );

    return (
      <div className="GiveawaysPage">
        <div className="GiveawaysPage-hero">
          <div className="container">
            <h1 className="GiveawaysPage-title">{t('heading')}</h1>
            <p className="GiveawaysPage-subtitle">{t('subheading')}</p>
            <div className="GiveawaysPage-actions">
              {this.canCreate && (
                <Button className="Button Button--primary" icon="fas fa-plus" onclick={() => this.create()}>
                  {t('create')}
                </Button>
              )}
              {this.canManage && (
                <Button className="Button" icon="fas fa-tags" onclick={() => this.manageCategories()}>
                  {t('categories.manage')}
                </Button>
              )}
            </div>
          </div>
        </div>

        <div className="container GiveawaysPage-content">
          <div className="GiveawaysPage-tabs" role="tablist">
            {this.tabs().map((tab) => (
              <button
                key={tab.id || 'all'}
                role="tab"
                aria-selected={this.status === tab.id}
                className={'GiveawayTab' + (this.status === tab.id ? ' is-active' : '')}
                onclick={() => this.setFilter({ status: tab.id })}
              >
                {tab.label}
              </button>
            ))}
          </div>

          {this.categories.length > 0 && (
            <div className="GiveawaysPage-filters">
              <button
                className={'GiveawayFilter' + (this.category === null ? ' is-active' : '')}
                onclick={() => this.setFilter({ category: null })}
              >
                {app.translator.trans('ygpynet-giveaways.forum.categories.all')}
              </button>
              {this.categories.map((c) => {
                const selected = this.category === c.id;
                return (
                  <button
                    key={c.id}
                    className={'GiveawayFilter' + (selected ? ' is-active' : '')}
                    style={
                      selected
                        ? { backgroundColor: c.color, borderColor: c.color, color: '#fff' }
                        : { color: c.color, borderColor: c.color }
                    }
                    onclick={() => this.setFilter({ category: selected ? null : c.id })}
                  >
                    {c.icon && <Icon name={c.icon} />} {c.name}
                  </button>
                );
              })}
            </div>
          )}

          {this.loading && !this.loadingMore ? (
            <LoadingIndicator />
          ) : this.giveaways.length === 0 ? (
            <div className="GiveawaysPage-empty">{t('empty')}</div>
          ) : (
            [
              drafts.length > 0 && (
                <section>
                  <h2 className="GiveawaysPage-sectionTitle">{t('draft_heading')}</h2>
                  {grid(drafts)}
                </section>
              ),
              active.length > 0 && (
                <section>
                  {this.status === '' && (
                    <h2 className="GiveawaysPage-sectionTitle">{t('active_heading')}</h2>
                  )}
                  {grid(active)}
                </section>
              ),
              past.length > 0 && (
                <section>
                  {this.status === '' && (
                    <h2 className="GiveawaysPage-sectionTitle">{t('past_heading')}</h2>
                  )}
                  {grid(past)}
                </section>
              ),
            ]
          )}

          {this.hasMore && (
            <div className="GiveawaysPage-loadMore">
              <Button className="Button Button--block" loading={this.loadingMore} onclick={() => this.loadMore()}>
                {t('load_more')}
              </Button>
            </div>
          )}
        </div>
      </div>
    );
  }
}
