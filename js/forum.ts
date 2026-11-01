import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import LinkButton from 'flarum/common/components/LinkButton';
import SessionDropdown from 'flarum/forum/components/SessionDropdown';
import { extend } from 'flarum/common/extend';

// Committee election pages: /elections (list) + /elections/:id (detail).
// Stages: nomination -> publicity -> voting -> ended. Progression is a
// button press by admin / group 7, never a cron. Candidates show nickname
// + verified badge only — no house data ever leaves the server here.

const m = (window as any).m;

const INPUT_STYLE: Record<string, string> = {
  width: '100%',
  boxSizing: 'border-box',
  fontSize: '16px',
  color: '#111827',
  backgroundColor: '#ffffff',
  border: '1px solid #cbd5e1',
  borderRadius: '6px',
  padding: '10px 12px',
};

const CARD_STYLE: Record<string, string> = {
  border: '1px solid #e2e8f0',
  borderRadius: '8px',
  padding: '12px',
  marginBottom: '12px',
  backgroundColor: '#ffffff',
};

const BADGE_VERIFIED: Record<string, string> = {
  display: 'inline-block',
  backgroundColor: '#1565C0',
  color: '#ffffff',
  borderRadius: '4px',
  padding: '2px 6px',
  fontSize: '13px',
  marginLeft: '6px',
};

function apiErr(e: any): string {
  try {
    const t = e && e.response && e.response.errors && e.response.errors[0] && e.response.errors[0].detail;
    if (t) return t;
  } catch (err) {}
  return '请求失败，请稍后再试。';
}

function req(method: string, url: string, body?: any): Promise<any> {
  return (app as any).request({ method, url, body });
}

// Server datetimes are UTC 'Y-m-d H:i:s'; display them in local time.
function fmtUTC(s: string | null | undefined): string {
  if (!s) return '—';
  const d = new Date(s.replace(' ', 'T') + 'Z');
  return isNaN(d.getTime()) ? s : d.toLocaleString();
}

// datetime-local (local wall time) -> ISO UTC for the server.
function toISO(local: string): string | null {
  if (!local) return null;
  const d = new Date(local);
  return isNaN(d.getTime()) ? null : d.toISOString();
}

function currentUserGroupIds(): string[] {
  const user = (app as any).session.user;
  if (!user) return [];
  try {
    return (user.groups() || []).map((g: any) => String(g.id()));
  } catch (e) {
    return [];
  }
}

function isManager(): boolean {
  const user = (app as any).session.user;
  if (!user) return false;
  try {
    if (user.isAdmin()) return true;
  } catch (e) {}
  return currentUserGroupIds().indexOf('7') >= 0;
}

function isResident(): boolean {
  return currentUserGroupIds().indexOf('6') >= 0;
}

function statusLabel(status: string): string {
  const map: Record<string, string> = {
    nomination: '提名中',
    publicity: '公示中',
    voting: '投票中',
    ended: '已结束',
  };
  return map[status] || status;
}

class ElectionsPage extends Component {
  elections: any[] = [];
  loading = true;
  error: string | null = null;
  notice: string | null = null;
  formOpen = false;
  formBusy = false;
  formError: string | null = null;
  form: any = { title: '', seat_count: '5', vote_ends_at: '', nomination_ends_at: '', publicity_ends_at: '' };

  oninit() {
    super.oninit();
    this.load();
  }

  load() {
    this.loading = true;
    req('GET', '/api/hooey/elections').then(
      (res: any) => {
        this.elections = res.data || [];
        this.loading = false;
        m.redraw();
      },
      (e: any) => {
        this.error = apiErr(e);
        this.loading = false;
        m.redraw();
      }
    );
  }

  submitCreate(e: any) {
    e.preventDefault();
    if (this.formBusy) return;
    const voteEnds = toISO(this.form.vote_ends_at);
    if (!this.form.title.trim() || !voteEnds) {
      this.formError = '请填写标题与投票截止时间。';
      return;
    }
    this.formBusy = true;
    this.formError = null;
    req('POST', '/api/hooey/elections', {
      title: this.form.title.trim(),
      seat_count: parseInt(this.form.seat_count, 10) || 1,
      vote_ends_at: voteEnds,
      nomination_ends_at: toISO(this.form.nomination_ends_at),
      publicity_ends_at: toISO(this.form.publicity_ends_at),
    }).then(
      () => {
        this.formBusy = false;
        this.formOpen = false;
        this.form = { title: '', seat_count: '5', vote_ends_at: '', nomination_ends_at: '', publicity_ends_at: '' };
        this.notice = '选举已创建，进入提名期。';
        this.load();
      },
      (err: any) => {
        this.formBusy = false;
        this.formError = apiErr(err);
        m.redraw();
      }
    );
  }

  view() {
    const user = (app as any).session.user;
    if (!user) {
      return m('div.container', [m('h2', '业委会选举'), m('p', '请登录后查看。')]);
    }
    if (this.loading) return m('div.container', [m('h2', '业委会选举'), m('p', '加载中…')]);
    if (this.error) return m('div.container', [m('h2', '业委会选举'), m('p', this.error)]);

    return m('div.container', [
      m('h2', '业委会选举'),
      this.notice ? m('div.Alert.Alert--success', this.notice) : null,
      isManager()
        ? m('div', { style: CARD_STYLE }, [
            m('button.Button.Button--primary', { onclick: () => { this.formOpen = !this.formOpen; } }, this.formOpen ? '收起新建选举' : '新建选举'),
            this.formOpen
              ? m('form', { onsubmit: (e: any) => this.submitCreate(e), style: { marginTop: '12px' } }, [
                  m('div.Form-group', [m('label.label', '标题'), m('input.FormControl', { style: INPUT_STYLE, value: this.form.title, oninput: (e: any) => { this.form.title = e.target.value; } })]),
                  m('div.Form-group', [m('label.label', '席位数'), m('input.FormControl', { style: INPUT_STYLE, type: 'number', min: 1, value: this.form.seat_count, oninput: (e: any) => { this.form.seat_count = e.target.value; } })]),
                  m('div.Form-group', [m('label.label', '投票截止时间'), m('input.FormControl', { style: INPUT_STYLE, type: 'datetime-local', value: this.form.vote_ends_at, oninput: (e: any) => { this.form.vote_ends_at = e.target.value; } })]),
                  m('div.Form-group', [m('label.label', '提名截止（仅展示，可空）'), m('input.FormControl', { style: INPUT_STYLE, type: 'datetime-local', value: this.form.nomination_ends_at, oninput: (e: any) => { this.form.nomination_ends_at = e.target.value; } })]),
                  m('div.Form-group', [m('label.label', '公示截止（仅展示，可空）'), m('input.FormControl', { style: INPUT_STYLE, type: 'datetime-local', value: this.form.publicity_ends_at, oninput: (e: any) => { this.form.publicity_ends_at = e.target.value; } })]),
                  this.formError ? m('div.Alert.Alert--error', this.formError) : null,
                  m('button.Button.Button--primary', { type: 'submit', disabled: this.formBusy }, this.formBusy ? '创建中…' : '创建选举'),
                ])
              : null,
          ])
        : null,
      this.elections.length === 0
        ? m('p', '暂无选举。')
        : this.elections.map((el: any) =>
            m('div', { style: CARD_STYLE }, [
              m('div', { style: { fontWeight: 'bold', fontSize: '16px' } }, el.title),
              m('div', `状态：${statusLabel(el.status)} · 席位：${el.seat_count} 名 · 候选人：${el.candidate_count} 人`),
              m('div', `投票截止：${fmtUTC(el.vote_ends_at)}`),
              m('div', { style: { marginTop: '8px' } }, [
                m(LinkButton, { href: '/elections/' + el.id }, '查看详情 →'),
              ]),
            ])
          ),
    ]);
  }
}

class ElectionPage extends Component {
  election: any = null;
  loading = true;
  error: string | null = null;
  notice: string | null = null;
  actionError: string | null = null;
  actionBusy: string | null = '';
  // nomination search
  nomineeQuery = '';
  nomineeResults: any[] = [];
  nomineeTimer: any = null;
  selectedNominee: any = null;
  nominateBusy = false;
  nominateError: string | null = null;
  // publicity edit
  publicityText = '';
  publicityBusy = false;
  publicityError: string | null = null;

  electionId(): string {
    try {
      return String(m.route.param('id'));
    } catch (e) {
      return '';
    }
  }

  oninit() {
    super.oninit();
    this.load();
  }

  load() {
    this.loading = true;
    req('GET', '/api/hooey/elections/' + this.electionId()).then(
      (res: any) => {
        this.election = res.data || null;
        this.publicityText = (this.election && this.election.publicity_text) || '';
        this.loading = false;
        m.redraw();
      },
      (e: any) => {
        this.error = apiErr(e);
        this.loading = false;
        m.redraw();
      }
    );
  }

  doAction(url: string, body: any, key: string, okMsg: string) {
    if (this.actionBusy) return;
    this.actionBusy = key;
    this.actionError = null;
    req('POST', url, body || {}).then(
      () => {
        this.actionBusy = '';
        this.notice = okMsg;
        this.load();
      },
      (e: any) => {
        this.actionBusy = '';
        this.actionError = apiErr(e);
        m.redraw();
      }
    );
  }

  onNomineeInput(e: any) {
    this.nomineeQuery = e.target.value;
    this.selectedNominee = null;
    const q = (this.nomineeQuery || '').trim();
    if (this.nomineeTimer) clearTimeout(this.nomineeTimer);
    if (q.length < 1) {
      this.nomineeResults = [];
      m.redraw();
      return;
    }
    this.nomineeTimer = setTimeout(() => {
      req('GET', '/api/users?filter[q]=' + encodeURIComponent(q)).then(
        (res: any) => {
          this.nomineeResults = (res.data || []).map((u: any) => ({
            id: u.id,
            username: u.attributes.username,
            name: u.attributes.displayName || u.attributes.username,
          }));
          m.redraw();
        },
        () => {
          this.nomineeResults = [];
          m.redraw();
        }
      );
    }, 300);
  }

  submitNomination(e: any) {
    e.preventDefault();
    if (!this.selectedNominee || this.nominateBusy) return;
    this.nominateBusy = true;
    this.nominateError = null;
    req('POST', '/api/hooey/elections/' + this.electionId() + '/nominations', {
      nominee_id: parseInt(this.selectedNominee.id, 10),
    }).then(
      () => {
        this.nominateBusy = false;
        this.selectedNominee = null;
        this.nomineeQuery = '';
        this.nomineeResults = [];
        this.notice = '提名已提交。';
        this.load();
      },
      (err: any) => {
        this.nominateBusy = false;
        this.nominateError = apiErr(err);
        m.redraw();
      }
    );
  }

  submitPublicity(e: any) {
    e.preventDefault();
    if (this.publicityBusy) return;
    this.publicityBusy = true;
    this.publicityError = null;
    req('POST', '/api/hooey/elections/' + this.electionId() + '/publicity', {
      publicity_text: this.publicityText,
    }).then(
      () => {
        this.publicityBusy = false;
        this.notice = '公示牌已保存。';
        this.load();
      },
      (err: any) => {
        this.publicityBusy = false;
        this.publicityError = apiErr(err);
        m.redraw();
      }
    );
  }

  removeCandidate(nid: number) {
    this.doAction('/api/hooey/elections/' + this.electionId() + '/nominations/' + nid + '/remove', {}, 'remove', '候选人已移除（记录保留）。');
  }

  view() {
    const user = (app as any).session.user;
    if (!user) {
      return m('div.container', [m('h2', '业委会选举'), m('p', '请登录后查看。')]);
    }
    if (this.loading) return m('div.container', [m('h2', '业委会选举'), m('p', '加载中…')]);
    if (this.error || !this.election) {
      return m('div.container', [m('h2', '业委会选举'), m('p', this.error || '选举不存在。')]);
    }

    const el = this.election;
    const manager = isManager();
    const resident = isResident();
    const nominations = el.nominations || [];
    const counts = el.vote_counts || {};
    const result = el.result || null;

    const nomineeVotes = (nomineeId: number) => {
      const v = counts[nomineeId] !== undefined ? counts[nomineeId] : counts[String(nomineeId)];
      return v === undefined ? 0 : v;
    };

    return m('div.container', [
      m('div', [m(LinkButton, { href: '/elections' }, '← 选举列表')]),
      m('h2', el.title),
      m('div', `状态：${statusLabel(el.status)} · 席位：${el.seat_count} 名`),
      m('div', `投票截止：${fmtUTC(el.vote_ends_at)}`),
      el.nomination_ends_at ? m('div', `提名截止（展示）：${fmtUTC(el.nomination_ends_at)}`) : null,
      el.publicity_ends_at ? m('div', `公示截止（展示）：${fmtUTC(el.publicity_ends_at)}`) : null,
      this.notice ? m('div.Alert.Alert--success', this.notice) : null,
      this.actionError ? m('div.Alert.Alert--error', this.actionError) : null,

      // Manager stage buttons
      manager && el.status === 'nomination'
        ? m('div', { style: { margin: '12px 0' } }, [
            m('button.Button.Button--primary', {
              disabled: !!this.actionBusy,
              onclick: () => this.doAction('/api/hooey/elections/' + this.electionId() + '/advance', {}, 'advance', '已推进到公示期。'),
            }, this.actionBusy === 'advance' ? '推进中…' : '推进到公示'),
          ])
        : null,
      manager && el.status === 'publicity'
        ? m('div', { style: { margin: '12px 0' } }, [
            m('button.Button.Button--primary', {
              disabled: !!this.actionBusy,
              onclick: () => this.doAction('/api/hooey/elections/' + this.electionId() + '/advance', {}, 'advance', '投票帖已生成，进入投票期。'),
            }, this.actionBusy === 'advance' ? '生成中…' : '开始投票'),
          ])
        : null,
      manager && el.status === 'voting'
        ? m('div', { style: { margin: '12px 0' } }, [
            m('button.Button.Button--primary', {
              disabled: !!this.actionBusy,
              onclick: () => this.doAction('/api/hooey/elections/' + this.electionId() + '/publish', {}, 'publish', '结果已公示。'),
            }, this.actionBusy === 'publish' ? '公示中…' : '公布结果'),
          ])
        : null,

      // Nomination form (group 6 only, nomination stage)
      el.status === 'nomination' && resident
        ? m('div', { style: CARD_STYLE }, [
            m('h3', '提名候选人'),
            m('p', '认证住户可提名认证住户（自荐或推荐他人），无需管理员审核。'),
            m('form', { onsubmit: (e: any) => this.submitNomination(e) }, [
              m('div.Form-group', [
                m('label.label', '搜索被提名人（用户名或昵称）'),
                m('input.FormControl', {
                  style: INPUT_STYLE,
                  value: this.nomineeQuery,
                  oninput: (e: any) => this.onNomineeInput(e),
                  placeholder: '输入用户名或昵称搜索',
                }),
                this.selectedNominee
                  ? m('div', { style: { marginTop: '4px' } }, ['已选：', this.selectedNominee.name])
                  : null,
                this.nomineeResults.length
                  ? m('ul', { style: { listStyle: 'none', padding: 0, margin: '4px 0 0', border: '1px solid #ddd', maxHeight: '200px', overflow: 'auto' } },
                      this.nomineeResults.map((u: any) =>
                        m('li', {
                          style: { padding: '6px 8px', cursor: 'pointer' },
                          onclick: () => {
                            this.selectedNominee = u;
                            this.nomineeQuery = u.name;
                            this.nomineeResults = [];
                            m.redraw();
                          },
                        }, u.name)
                      ))
                  : null,
              ]),
              this.nominateError ? m('div.Alert.Alert--error', this.nominateError) : null,
              m('button.Button.Button--primary', { type: 'submit', disabled: !this.selectedNominee || this.nominateBusy }, this.nominateBusy ? '提交中…' : '提交提名'),
            ]),
          ])
        : null,

      // Publicity board
      (el.status === 'publicity' || el.status === 'voting' || el.status === 'ended') && (el.publicity_text || manager)
        ? m('div', { style: CARD_STYLE }, [
            m('h3', '公示牌'),
            el.publicity_text ? m('p', el.publicity_text) : m('p', '公示牌暂无内容。'),
            manager && el.status === 'publicity'
              ? m('form', { onsubmit: (e: any) => this.submitPublicity(e), style: { marginTop: '8px' } }, [
                  m('div.Form-group', [
                    m('label.label', '填写公示牌（管理员或业委会成员）'),
                    m('textarea.FormControl', {
                      style: { ...INPUT_STYLE, minHeight: '80px' },
                      value: this.publicityText,
                      oninput: (e: any) => { this.publicityText = e.target.value; },
                    }),
                  ]),
                  this.publicityError ? m('div.Alert.Alert--error', this.publicityError) : null,
                  m('button.Button.Button--primary', { type: 'submit', disabled: this.publicityBusy }, this.publicityBusy ? '保存中…' : '保存公示牌'),
                ])
              : null,
          ])
        : null,

      // Candidate list
      m('div', { style: CARD_STYLE }, [
        m('h3', '候选人'),
        nominations.length === 0
          ? m('p', '暂无提名。')
          : nominations.map((n: any) =>
              m('div', { style: { borderBottom: '1px solid #f1f5f9', padding: '8px 0' } }, [
                m('span', { style: { fontWeight: 'bold', fontSize: '16px' } }, n.nominee ? n.nominee.nickname : '—'),
                n.nominee ? m('span', { style: BADGE_VERIFIED }, '已认证') : null,
                m('span', { style: { marginLeft: '8px', color: '#64748b' } }, n.status === 'removed' ? '已移除' : '候选人'),
                m('div', { style: { color: '#64748b', fontSize: '14px' } }, `提名人：${n.nominator ? n.nominator.nickname : '—'} · 提名于 ${fmtUTC(n.created_at)}`),
                (el.status === 'voting' || el.status === 'ended') && n.status === 'candidate'
                  ? m('div', `得票：${nomineeVotes(n.nominee.id)} 票`)
                  : null,
                manager && el.status === 'publicity' && n.status === 'candidate'
                  ? m('div', { style: { marginTop: '4px' } }, [
                      m('button.Button.Button--danger', {
                        disabled: !!this.actionBusy,
                        onclick: () => this.removeCandidate(n.id),
                      }, '移除候选人'),
                    ])
                  : null,
              ])
            ),
      ]),

      // Vote link
      el.status === 'voting' && el.discussion_id
        ? m('div', { style: CARD_STYLE }, [
            m('h3', '投票'),
            m('p', '业委会投票仅认证住户可投，每人限投 1 票，截止前可改投。'),
            m(LinkButton, { href: '/d/' + el.discussion_id }, '前往投票帖 →'),
          ])
        : null,

      // Results
      el.status === 'ended' && result
        ? m('div', { style: CARD_STYLE }, [
            m('h3', '结果公示'),
            m('div', '当选名单：'),
            m('ul', (result.winners || []).map((w: any) => m('li', `${w.name}（${w.votes} 票）`))),
            m('div', '全部候选人得票：'),
            m('ul', (result.ranked || []).map((r: any, i: number) => m('li', `第 ${i + 1} 名：${r.name}（${r.votes} 票）`))),
            result.tie_at_cutoff ? m('p', '说明：第 ' + el.seat_count + ' 名出现平票，按最早提名者优先确定当选。') : null,
            el.result_discussion_id ? m('div', [m(LinkButton, { href: '/d/' + el.result_discussion_id }, '查看结果公示帖 →')]) : null,
          ])
        : null,
    ]);
  }
}

(app as any).initializers.add('leungking-election', () => {
  (app as any).routes['hooey.elections'] = {
    path: '/elections',
    component: ElectionsPage,
  };
  (app as any).routes['hooey.election'] = {
    path: '/elections/:id',
    component: ElectionPage,
  };

  // Header user menu: election entry, login-only by construction
  // (SessionDropdown only renders for logged-in users).
  extend((SessionDropdown as any).prototype, 'items', function (this: any, items: any) {
    items.add(
      'elections',
      m(LinkButton, { icon: 'fas fa-vote-yea', href: (app as any).route('hooey.elections') }, '选举'),
      50
    );
  });
});
