<?php

namespace Convoro\Ext\Giveaways;

use App\Models\User;
use App\Support\ExtensionManager;
use App\Support\ExtPage;
use Convoro\Ext\Giveaways\Models\Giveaway;
use Convoro\Ext\Giveaways\Models\GiveawayEntry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

/**
 * Giveaways — first-party Convoro extension.
 *
 * Members enter an active giveaway with one click; a commit–reveal seed makes
 * the draw provably fair (winner = lowest sha256(seed:user_id), recomputable by
 * anyone from the revealed seed + entrant list, with the seed pre-committed via
 * its hash). Ships a forum sidebar widget, a public giveaways page with a
 * fairness verifier, scheduled auto-draws and a themed admin manager.
 */
class Extension extends ServiceProvider
{
    private const ID = 'convoro-giveaways';

    public function boot(): void
    {
        $this->registerRoutes();

        // Auto-draw: run every minute via the host scheduler. The command is also
        // runnable by hand (`php artisan convoro:giveaways-draw`).
        if ($this->app->runningInConsole()) {
            $this->commands([DrawDueCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command(DrawDueCommand::class)
                ->everyMinute()
                ->name('convoro-giveaways-draw')
                ->withoutOverlapping();
        });
    }

    private static function setting(string $key, mixed $default = null): mixed
    {
        return ExtensionManager::setting(self::ID, $key, $default);
    }

    private static function e(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES);
    }

    /** Validation rules shared by create and edit. */
    private static function fieldRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'prize' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'rules' => ['nullable', 'string', 'max:20000'],
            'skill_question' => ['nullable', 'string', 'max:300'],
            'skill_answer' => ['nullable', 'string', 'max:120', 'required_with:skill_question'],
            'skill_attempt_limit' => ['nullable', 'integer', 'min:0', 'max:10'],
            'image_path' => ['nullable', 'string', 'max:600'],
            'ends_at' => ['nullable', 'date'],
        ];
    }

    /**
     * Copy validated fields onto a giveaway. Never touches the seed, the
     * commitment or the recorded winner — editing must not be able to disturb
     * the fairness proof.
     */
    private static function applyFields(Giveaway $g, array $data): void
    {
        $g->title = $data['title'];
        $g->prize = $data['prize'];
        $g->description = self::blankToNull($data['description'] ?? null);
        $g->rules = self::blankToNull($data['rules'] ?? null);
        $g->skill_question = self::blankToNull($data['skill_question'] ?? null);
        $g->skill_answer = $g->skill_question ? self::blankToNull($data['skill_answer'] ?? null) : null;
        $g->skill_attempt_limit = $g->skill_question ? max(0, min(10, (int) ($data['skill_attempt_limit'] ?? 0))) : 0;
        $g->image_path = self::safeImage($data['image_path'] ?? null);
        $g->ends_at = $data['ends_at'] ?? null;
    }

    private static function blankToNull(?string $v): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }

    /**
     * Render operator-authored plain text (rules, description) as escaped HTML.
     * Line breaks are preserved via CSS `white-space: pre-wrap`, so no markup
     * from the author is ever interpreted.
     */
    private static function text(?string $v): string
    {
        return self::e(trim((string) $v));
    }

    /** Only allow http(s) / root-relative image URLs (e.g. from /uploads/image). */
    private static function safeImage(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        return (str_starts_with($url, 'https://') || str_starts_with($url, 'http://') || str_starts_with($url, '/')) ? $url : null;
    }

    // --- Routes ------------------------------------------------------------

    private function registerRoutes(): void
    {
        // Public API: the current active giveaway (+ whether the viewer entered).
        \Illuminate\Support\Facades\Route::middleware('web')->get('/api/ext/giveaways/active', function (Request $request) {
            // Opportunistic auto-draw so winners are picked on schedule even on
            // hosts without a cron-driven scheduler. perform() is transactional
            // and idempotent, so this is race-safe.
            Draw::drawDue();

            if (! self::setting('show_widget', true)) {
                return response()->json(['giveaway' => null]);
            }

            $g = Giveaway::where('active', true)->orderByDesc('id')->first();
            if (! $g) {
                return response()->json(['giveaway' => null]);
            }
            $entries = $g->entries()->count();
            $entered = $request->user()
                ? $g->entries()->where('user_id', $request->user()->id)->exists()
                : false;

            return response()->json(['giveaway' => [
                'id' => $g->id, 'title' => $g->title, 'prize' => $g->prize,
                'description' => $g->description, 'image' => self::safeImage($g->image_path),
                'rules' => $g->rules,
                // Published with the rules so entrants can read it up front; the
                // winner answers it after the draw, and the expected answer
                // never leaves the server.
                'skillQuestion' => $g->requiresSkillAnswer() ? $g->skill_question : null,
                'endsAt' => optional($g->ends_at)->toIso8601String(),
                'entries' => $entries, 'entered' => $entered,
                'authed' => (bool) $request->user(),
            ]]);
        });

        // Enter (auth).
        \Illuminate\Support\Facades\Route::middleware(['web', 'auth'])->post('/api/ext/giveaways/{id}/enter', function (Request $request, int $id) {
            $g = Giveaway::where('id', $id)->where('active', true)->first();
            abort_if(! $g, 404);
            if ($g->hasEnded()) {
                return response()->json(['ok' => false, 'message' => 'This giveaway has ended.'], 422);
            }
            GiveawayEntry::updateOrCreate(
                ['giveaway_id' => $id, 'user_id' => $request->user()->id],
                ['created_at' => now()],
            );

            return response()->json(['ok' => true, 'entries' => $g->entries()->count()]);
        });

        // Claim (auth): the drawn winner answers the skill-testing question.
        // The question qualifies the AWARD, not the entry, so this is the only
        // place an answer is ever checked.
        \Illuminate\Support\Facades\Route::middleware(['web', 'auth'])->post('/api/ext/giveaways/{id}/claim', function (Request $request, int $id) {
            $g = Giveaway::find($id);
            abort_if(! $g, 404);
            if ((int) $g->winner_user_id !== (int) $request->user()->id) {
                return response()->json(['ok' => false, 'message' => 'You are not the winner of this giveaway.'], 403);
            }
            if ($g->skill_verified_at) {
                return response()->json(['ok' => true, 'verified' => true]);
            }
            if (! $g->requiresSkillAnswer()) {
                return response()->json(['ok' => false, 'message' => 'This giveaway has no skill-testing question.'], 422);
            }
            $answer = is_string($request->input('answer')) ? trim($request->input('answer')) : '';

            // No answer is not a wrong answer: it never burns an attempt.
            if ($answer === '') {
                return response()->json(['ok' => false, 'message' => 'Answer the skill-testing question to claim your prize.'], 422);
            }

            if (! $g->skillAnswerMatches($answer)) {
                $g->skill_attempts = (int) $g->skill_attempts + 1;
                $limit = $g->skillAttemptLimit();

                if ($limit > 0 && $g->skill_attempts >= $limit) {
                    // Out of attempts: forfeit, and promote the next-lowest
                    // hash under the same published seed.
                    $g->save();
                    Draw::forfeitAndRedraw($g->id);

                    return response()->json([
                        'ok' => false, 'forfeited' => true,
                        'message' => 'That was your last attempt, so the prize has been redrawn.',
                    ], 422);
                }

                $g->save();
                $left = $limit > 0 ? $limit - $g->skill_attempts : null;

                return response()->json([
                    'ok' => false,
                    'attemptsLeft' => $left,
                    'message' => $left === null
                        ? 'That answer isn\'t right — check the question and try again.'
                        : 'That answer isn\'t right — '.$left.' '.($left === 1 ? 'attempt' : 'attempts').' left before the prize is redrawn.',
                ], 422);
            }
            $g->skill_verified_at = now();
            $g->save();

            return response()->json(['ok' => true, 'verified' => true]);
        });

        // Public pages: giveaways index + per-giveaway fairness verifier.
        \Illuminate\Support\Facades\Route::middleware('web')->group(function () {
            \Illuminate\Support\Facades\Route::get('/giveaways', fn () => self::indexPage());
            \Illuminate\Support\Facades\Route::get('/giveaways/{id}/verify', function (int $id) {
                $g = Giveaway::find($id);
                abort_if(! $g, 404);

                return self::verifyPage($g);
            });
        });

        // Admin manager + CRUD (admin-only via the admin area).
        \Illuminate\Support\Facades\Route::middleware(['web', 'auth', 'admin'])->prefix('admin/ext/giveaways')->group(function () {
            \Illuminate\Support\Facades\Route::get('/', fn () => self::adminPage());

            \Illuminate\Support\Facades\Route::get('/list', function () {
                return response()->json(Giveaway::orderByDesc('id')->get()->map(function (Giveaway $g) {
                    return [
                        'id' => $g->id, 'title' => $g->title, 'prize' => $g->prize,
                        'image' => self::safeImage($g->image_path),
                        'active' => $g->active, 'entries' => $g->entries()->count(),
                        'hasRules' => trim((string) $g->rules) !== '',
                        'skillQuestion' => $g->requiresSkillAnswer() ? $g->skill_question : null,
                        'awaitingSkill' => $g->awaitingSkillAnswer(),
                        'forfeits' => count($g->forfeitedUserIds()),
                        'skillVerifiedAt' => optional($g->skill_verified_at)->toIso8601String(),
                        'winner' => $g->winner_user_id ? optional(User::find($g->winner_user_id))->name : null,
                        // The commitment is always public; the raw seed is only
                        // revealed after the draw.
                        'seedHash' => $g->seed_hash,
                        'seed' => $g->drawn_at ? $g->seed : null,
                        'endsAt' => optional($g->ends_at)->toIso8601String(),
                        'drawnAt' => optional($g->drawn_at)->toIso8601String(),
                    ];
                }));
            });

            \Illuminate\Support\Facades\Route::post('/', function (Request $request) {
                $data = $request->validate(self::fieldRules());
                [$seed, $seedHash] = Draw::newSeed();
                $g = new Giveaway;
                self::applyFields($g, $data);
                $g->seed = $seed;
                $g->seed_hash = $seedHash;
                $g->active = true;
                $g->save();

                return response()->json(['id' => $g->id]);
            });

            // Full record for the edit form. Admin-only, so it may include the
            // skill-testing answer — that never reaches the public endpoints.
            \Illuminate\Support\Facades\Route::get('/{id}', function (int $id) {
                $g = Giveaway::find($id);
                abort_if(! $g, 404);

                return response()->json([
                    'id' => $g->id, 'title' => $g->title, 'prize' => $g->prize,
                    'description' => $g->description, 'rules' => $g->rules,
                    'skill_question' => $g->skill_question, 'skill_answer' => $g->skill_answer,
                    'skill_attempt_limit' => (int) $g->skill_attempt_limit,
                    'image_path' => self::safeImage($g->image_path),
                    // datetime-local wants 'Y-m-dTH:i' in the site's timezone.
                    'ends_at' => optional($g->ends_at)->format('Y-m-d\\TH:i'),
                    'drawnAt' => optional($g->drawn_at)->toIso8601String(),
                ]);
            });

            // Edit. Details (including the rules) stay correctable after
            // creation; the seed and commitment are never rewritten, so the
            // fairness proof is unaffected. Drawn giveaways are frozen — their
            // rules are the published record of how the winner was picked.
            \Illuminate\Support\Facades\Route::post('/{id}', function (Request $request, int $id) {
                $g = Giveaway::find($id);
                abort_if(! $g, 404);
                if ($g->drawn_at) {
                    return response()->json(['ok' => false, 'message' => 'A drawn giveaway can\'t be edited.'], 422);
                }
                $data = $request->validate(self::fieldRules());
                self::applyFields($g, $data);
                $g->save();

                return response()->json(['ok' => true, 'id' => $g->id]);
            });

            \Illuminate\Support\Facades\Route::post('/{id}/draw', function (int $id) {
                $g = Giveaway::find($id);
                abort_if(! $g, 404);
                if ($g->drawn_at) {
                    return response()->json(['ok' => false, 'message' => 'Already drawn.'], 422);
                }
                $winnerId = Draw::perform($id);
                if ($winnerId === null) {
                    return response()->json(['ok' => false, 'message' => 'No entries to draw from.'], 422);
                }

                return response()->json(['ok' => true, 'winner' => optional(User::find($winnerId))->name]);
            });

            \Illuminate\Support\Facades\Route::post('/{id}/toggle', function (int $id) {
                $g = Giveaway::find($id);
                abort_if(! $g, 404);
                if ($g->drawn_at) {
                    return response()->json(['ok' => false, 'message' => 'A drawn giveaway can\'t be reopened.'], 422);
                }
                $g->active = ! $g->active;
                $g->save();

                return response()->json(['active' => $g->active]);
            });

            \Illuminate\Support\Facades\Route::delete('/{id}', function (int $id) {
                Giveaway::whereKey($id)->delete();

                return response()->json(['ok' => true]);
            });
        });
    }

    // --- Public pages ------------------------------------------------------

    /**
     * Collapsible "Official rules" block. Rules are often required to be
     * published in full (eligibility, selection method, deadlines, odds…) and
     * can run long, so they live behind a disclosure rather than stretching the
     * card. Rendered as escaped plain text with line breaks preserved.
     */
    private static function rulesHtml(Giveaway $g, bool $open = false): string
    {
        $blocks = '';
        if ($g->requiresSkillAnswer()) {
            $blocks .= '<div class="gv-skill"><span class="gv-k">Skill-testing question</span>'
                .'<div class="gv-skill-q">'.self::text($g->skill_question).'</div>'
                .'<span class="gv-note">The drawn winner must answer this correctly before the prize is awarded.'
                .($g->skillAttemptLimit() > 0
                    ? ' After '.$g->skillAttemptLimit().' wrong '.($g->skillAttemptLimit() === 1 ? 'answer' : 'answers')
                        .', the prize is redrawn for the next entrant the draw seed picks.'
                    : '')
                .'</span></div>';
        }
        if (trim((string) $g->rules) !== '') {
            $blocks .= '<div class="gv-rules-body">'.self::text($g->rules).'</div>';
        }
        if ($blocks === '') {
            return '';
        }

        return '<details class="gv-rules"'.($open ? ' open' : '').'>'
            .'<summary class="gv-rules-s">📋 Official rules</summary>'.$blocks.'</details>';
    }

    /**
     * Panel shown to a logged-in winner who has not yet answered the
     * skill-testing question. Until they do, the prize isn't awarded — the
     * question qualifies the award, which is why it is asked here rather than
     * at entry.
     */
    private static function winnerPanels(): array
    {
        $user = Auth::user();
        if (! $user) {
            return ['', ''];
        }

        $pending = Giveaway::where('winner_user_id', $user->id)
            ->whereNull('skill_verified_at')
            ->whereNotNull('drawn_at')
            ->orderByDesc('drawn_at')
            ->get()
            ->filter(fn (Giveaway $g) => $g->requiresSkillAnswer());

        // Someone who forfeited is told so, rather than watching their panel
        // quietly disappear.
        $forfeited = Giveaway::whereNotNull('forfeited_user_ids')
            ->whereNotNull('drawn_at')
            ->orderByDesc('drawn_at')->limit(20)->get()
            ->filter(fn (Giveaway $g) => in_array((int) $user->id, $g->forfeitedUserIds(), true));

        if ($pending->isEmpty() && $forfeited->isEmpty()) {
            return ['', ''];
        }

        $html = '';
        foreach ($forfeited as $g) {
            $html .= '<div class="gv-won gv-won-lost">'
                .'<div class="gv-won-h">The prize has been redrawn — '.self::e($g->title).'</div>'
                .'<p class="gv-won-sub">The skill-testing question wasn\'t answered correctly in the attempts allowed, '
                .'so the prize passed to the next entrant the draw seed picks. '
                .'<a href="/giveaways/'.$g->id.'/verify">See the proof</a>.</p></div>';
        }
        foreach ($pending as $g) {
            $html .= '<div class="gv-won" data-id="'.(int) $g->id.'">'
                .'<div class="gv-won-h">🎉 You won — '.self::e($g->title).'</div>'
                .'<div class="gv-prize">🏆 '.self::e($g->prize).'</div>'
                .'<p class="gv-won-sub">Before your prize can be awarded, answer this skill-testing question.</p>'
                .'<div class="gv-skill-q">'.self::text($g->skill_question).'</div>'
                .($g->skillAttemptLimit() > 0
                    ? '<div class="gv-attempts">'.max(0, $g->skillAttemptLimit() - (int) $g->skill_attempts)
                        .' attempts left — if you run out, the prize is redrawn.</div>'
                    : '')
                .'<div class="gv-won-row"><input class="gv-ans" type="text" autocomplete="off" placeholder="Your answer">'
                .'<button type="button" class="gv-btn gv-btn-p gv-claim">Claim prize</button></div>'
                .'<div class="gv-won-msg"></div></div>';
        }

        $js = <<<'JS'
        (function () {
          function csrf() { var m = document.querySelector('meta[name="csrf-token"]'); return m ? m.getAttribute('content') : ''; }
          document.querySelectorAll('.gv-won').forEach(function (card) {
            var input = card.querySelector('.gv-ans');
            var btn = card.querySelector('.gv-claim');
            var msg = card.querySelector('.gv-won-msg');
            function claim() {
              var answer = (input.value || '').trim();
              if (!answer) { msg.className = 'gv-won-msg gv-bad'; msg.textContent = 'Answer the skill-testing question to claim your prize.'; input.focus(); return; }
              btn.disabled = true; btn.textContent = 'Checking…';
              fetch('/api/ext/giveaways/' + card.getAttribute('data-id') + '/claim', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
                credentials: 'same-origin',
                body: JSON.stringify({ answer: answer })
              }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
                .then(function (x) {
                  if (x.ok && x.d.ok) {
                    card.classList.add('gv-won-done');
                    card.querySelector('.gv-won-row').remove();
                    msg.className = 'gv-won-msg gv-ok';
                    msg.textContent = '✓ Correct — your prize is confirmed. It now shows as answered on the giveaways panel.';
                  } else {
                    btn.disabled = false; btn.textContent = 'Claim prize';
                    msg.className = 'gv-won-msg gv-bad';
                    msg.textContent = (x.d && x.d.message) || 'Could not check that answer.';
                    input.focus();
                  }
                }).catch(function () { btn.disabled = false; btn.textContent = 'Claim prize'; });
            }
            btn.addEventListener('click', claim);
            input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); claim(); } });
          });
        })();
        JS;

        return [$html, $js];
    }

    private static function indexPage(): \Inertia\Response
    {
        [$wonHtml, $wonJs] = self::winnerPanels();
        $active = Giveaway::where('active', true)->orderByDesc('id')->first();
        $past = Giveaway::whereNotNull('drawn_at')->orderByDesc('drawn_at')->limit(20)->get();
        $names = User::whereIn('id', $past->pluck('winner_user_id')->filter()->unique())->pluck('name', 'id');

        $activeImg = $active && self::safeImage($active->image_path)
            ? '<div class="gv-card-img" style="background-image:url(\''.self::e(self::safeImage($active->image_path)).'\')"></div>' : '';
        $activeHtml = $active
            ? '<div class="gv-card gv-active">'.$activeImg
                .'<div class="gv-badge">🎁 Active giveaway</div>'
                .'<h2 class="gv-t">'.self::e($active->title).'</h2>'
                .'<div class="gv-prize">🏆 '.self::e($active->prize).'</div>'
                .($active->description ? '<p class="gv-desc">'.self::e($active->description).'</p>' : '')
                .'<div class="gv-meta">'.number_format($active->entries()->count()).' entries'
                .($active->ends_at ? ' · ends '.self::e($active->ends_at->toFormattedDayDateString()) : '').'</div>'
                .self::rulesHtml($active)
                .'<div class="gv-actions"><a class="gv-btn gv-btn-p" href="/giveaways/'.$active->id.'/verify">Verify fairness</a></div>'
                .'</div>'
            : '<div class="gv-blank"><div class="gv-blank-ico">🎁</div><div class="gv-blank-t">No active giveaway right now</div><p class="gv-muted">Check back soon.</p></div>';

        $rows = '';
        foreach ($past as $g) {
            $winner = $g->winner_user_id ? ($names[$g->winner_user_id] ?? 'Member') : '—';
            $rows .= '<a class="gv-row" href="/giveaways/'.$g->id.'/verify">'
                .'<div class="gv-row-b"><div class="gv-row-t">'.self::e($g->title).'</div>'
                .'<div class="gv-row-m">🏆 '.self::e($g->prize).' · won by <b>'.self::e($winner).'</b></div></div>'
                .'<span class="gv-verify">Verify →</span></a>';
        }
        $pastHtml = $past->count()
            ? '<section class="gv-sec"><h3 class="gv-h3">Past winners</h3><div class="gv-list">'.$rows.'</div></section>'
            : '';

        $body = '<div class="gv-wrap"><div class="gv-hero"><div class="gv-eyebrow">Giveaways</div>'
            .'<h1 class="gv-h1">Win prizes — provably fair</h1>'
            .'<p class="gv-sub">Enter with one click. Every draw is verifiable: the winning seed is committed up front and revealed at the draw, so anyone can recompute the winner.</p></div>'
            .$wonHtml.$activeHtml.$pastHtml.'</div>';

        return ExtPage::render('Giveaways', $body, self::css(), $wonJs);
    }

    private static function verifyPage(Giveaway $g): \Inertia\Response
    {
        $drawn = $g->drawn_at !== null;
        $ids = GiveawayEntry::where('giveaway_id', $g->id)->orderBy('user_id')->pluck('user_id')->all();
        $forfeited = $g->forfeitedUserIds();
        $winnerName = $g->winner_user_id ? (optional(User::find($g->winner_user_id))->name ?? 'Member') : null;

        $commit = '<div class="gv-kv"><span class="gv-k">Commitment (published at creation)</span>'
            .'<code class="gv-code" id="gv-hash">'.self::e($g->seed_hash).'</code>'
            .'<span class="gv-note">sha256 of the secret seed</span></div>';

        if ($drawn) {
            $reveal = '<div class="gv-kv"><span class="gv-k">Revealed seed</span><code class="gv-code" id="gv-seed">'.self::e($g->seed).'</code></div>'
                .'<div class="gv-kv"><span class="gv-k">Entrants ('.count($ids).')</span><code class="gv-code" id="gv-ids">'.self::e(implode(',', $ids)).'</code></div>'
                .'<div class="gv-kv"><span class="gv-k">Forfeited ('.count($forfeited).')</span>'
                .'<code class="gv-code" id="gv-forf">'.self::e(implode(',', $forfeited)).'</code>'
                .'<span class="gv-note">'.($forfeited
                    ? 'These entrants were drawn first but did not answer the skill-testing question, so they are excluded and the next-lowest hash wins.'
                    : 'No one has forfeited — the winner is the lowest hash over the whole entrant list.').'</span></div>'
                .'<div class="gv-kv"><span class="gv-k">Recorded winner</span><span class="gv-win" id="gv-winner" data-id="'.($g->winner_user_id ? (int) $g->winner_user_id : '').'">'
                .($g->winner_user_id
                    ? self::e($winnerName).' (user #'.(int) $g->winner_user_id.')'
                    : '— none: every entrant forfeited').'</span></div>'
                .'<div class="gv-check" id="gv-check">Recomputing in your browser…</div>';
            $js = <<<JS
            async function h(s){const b=await crypto.subtle.digest('SHA-256',new TextEncoder().encode(s));return [...new Uint8Array(b)].map(x=>x.toString(16).padStart(2,'0')).join('');}
            (async function(){
              var seed=document.getElementById('gv-seed').textContent.trim();
              var idsTxt=document.getElementById('gv-ids').textContent.trim();
              var ids=idsTxt?idsTxt.split(',').map(function(x){return x.trim();}).filter(Boolean):[];
              // A forfeited entrant is excluded from selection: the winner is the
              // lowest hash among those who did NOT forfeit. The list is published
              // above, so this stays a pure function of (seed, entrants, forfeits).
              var forfTxt=document.getElementById('gv-forf').textContent.trim();
              var forf=forfTxt?forfTxt.split(',').map(function(x){return x.trim();}).filter(Boolean):[];
              if(forf.length){ids=ids.filter(function(id){return forf.indexOf(id)===-1;});}
              var recordedHash=document.getElementById('gv-hash').textContent.trim();
              var recordedWinner=document.getElementById('gv-winner').getAttribute('data-id');
              var check=document.getElementById('gv-check');
              var seedOk=(await h(seed))===recordedHash;
              var best=null,bestH=null;
              for(var i=0;i<ids.length;i++){var hv=await h(seed+':'+ids[i]);if(bestH===null||hv<bestH){bestH=hv;best=ids[i];}}
              // No recorded winner is correct only if nobody eligible was left.
              var winnerOk=recordedWinner?String(best)===String(recordedWinner):best===null;
              if(seedOk&&winnerOk){check.className='gv-check gv-ok';check.textContent='✓ Verified — sha256(seed) matches the commitment, and the winner is the entrant with the lowest sha256(seed:id)'+(forf.length?' among those who did not forfeit':'')+'.';}
              else{check.className='gv-check gv-bad';check.textContent='✗ Verification failed'+(seedOk?'':' — seed does not match the published commitment')+(winnerOk?'':' — recomputed winner differs from the recorded one')+'.';}
            })();
            JS;
        } else {
            $reveal = '<div class="gv-pending">The winner hasn\'t been drawn yet. The secret seed is revealed here the moment it is, so you can confirm it matches the commitment above.</div>'
                .'<div class="gv-kv"><span class="gv-k">Entrants so far</span><span class="gv-note">'.count($ids).'</span></div>';
            $js = '';
        }

        $body = '<div class="gv-wrap gv-narrow"><div class="gv-crumbs"><a href="/giveaways">Giveaways</a> <span>/</span> Verify</div>'
            .'<div class="gv-card"><div class="gv-badge">'.($drawn ? '✅ Drawn' : '⏳ Open').'</div>'
            .'<h1 class="gv-h2">'.self::e($g->title).'</h1><div class="gv-prize">🏆 '.self::e($g->prize).'</div>'
            .'<div class="gv-how">Winner = the entrant whose <code>sha256(seed : user-id)</code> is lowest. '
            .'The seed is committed (hashed) before entries open and revealed at the draw, so the operator can\'t change it after the fact.'
            .($forfeited ? ' A winner who fails the skill-testing question forfeits and is excluded; the prize passes to the next-lowest hash, which is why the forfeited list is published below — the redraw is not a fresh draw.' : '').'</div>'
            .self::rulesHtml($g).$commit.$reveal.'</div></div>';

        return ExtPage::render('Verify giveaway', $body, self::css(), $js);
    }

    // --- Admin -------------------------------------------------------------

    private static function adminPage(): \Inertia\Response
    {
        $body = <<<HTML
        <div class="gv-wrap gv-narrow">
          <div class="gv-hero gv-hero-sm">
            <div class="gv-eyebrow">Giveaways</div>
            <h1 class="gv-h1">Run a giveaway</h1>
            <p class="gv-sub">The latest active giveaway shows in the forum sidebar and on the public <a href="/giveaways" target="_blank">Giveaways</a> page. Draws are provably fair from a pre-committed seed.</p>
          </div>
          <div class="gv-card" id="form-card">
            <div class="gv-form-h" id="form-h">New giveaway</div>
            <label class="gv-f">Title</label><input id="title" placeholder="December community giveaway">
            <label class="gv-f">Prize</label><input id="prize" placeholder="$50 gift card">
            <label class="gv-f">Description (optional)</label><textarea id="description" rows="2"></textarea>
            <label class="gv-f">Official rules (optional)</label>
            <textarea id="rules" rows="8" placeholder="Who may enter, how and when the winner is selected, the entry deadline, how the winner is contacted, odds of winning, sponsor details…"></textarea>
            <p class="gv-help">Shown in full on the Giveaways page, the sidebar widget and the giveaway's permanent verification page. Plain text — line breaks are kept.</p>
            <label class="gv-f">Skill-testing question (optional)</label>
            <input id="skill_question" placeholder="What is 12 + 7 × 2?">
            <label class="gv-f">Correct answer</label>
            <input id="skill_answer" placeholder="26">
            <label class="gv-f">Attempts before forfeit</label>
            <input id="skill_attempt_limit" type="number" min="0" max="10" placeholder="0">
            <p class="gv-help">Where a pure game of chance isn't allowed, the drawn winner must answer this correctly before the prize is awarded — entering stays one click. Matching ignores case, spaces and thousands separators. Attempts before forfeit: how many wrong answers the winner may give before the prize is redrawn for the next entrant the seed picks — 0 lets them keep trying.</p>
            <label class="gv-f">Image (optional)</label>
            <div class="gv-img-row"><div class="gv-img-prev" id="img-prev"></div>
              <input type="file" id="img-file" accept="image/*" hidden>
              <button type="button" class="gv-btn gv-btn-ghost" id="img-btn">Upload image</button>
              <button type="button" class="gv-btn gv-btn-x" id="img-clear" hidden>Remove</button></div>
            <label class="gv-f">Ends at (optional — auto-draws when reached)</label><input id="ends_at" type="datetime-local">
            <div class="gv-create"><button class="gv-btn gv-btn-p" id="add">Create giveaway</button>
              <button type="button" class="gv-btn gv-btn-ghost" id="cancel" hidden>Cancel</button></div>
          </div>
          <div class="gv-card"><div id="list" class="gv-muted">Loading…</div></div>
        </div>
        HTML;

        return ExtPage::render('Giveaways', $body, self::css(), self::adminJs());
    }

    private static function adminJs(): string
    {
        return <<<JS
        function notify(m,k){try{if(window.parent!==window)window.parent.postMessage({type:'convoro:toast',message:m,kind:k||'success'},location.origin);}catch(e){}}
        var esc=function(s){return (s||'').replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});};
        function load(){fetch('/admin/ext/giveaways/list',{headers:H}).then(function(r){return r.json();}).then(function(rows){
          var el=document.getElementById('list');
          if(!rows.length){el.innerHTML='<p class="gv-muted">No giveaways yet.</p>';return;}
          el.innerHTML=rows.map(function(g){
            var status=g.drawnAt?'drawn':(g.active?'on':'off');
            var win=g.winner?(' · winner: <span class="gv-win">'+esc(g.winner)+'</span>'):'';
            var seedLine=g.seed?('seed: '+esc(g.seed)):('commit: '+esc(g.seedHash));
            var skill=g.skillQuestion?(g.awaitingSkill?' · <span class="gv-warn">awaiting winner\'s answer</span>'
              :(g.skillVerifiedAt?' · <span class="gv-win">winner answered ✓</span>':' · <span class="gv-flag">skill question</span>')):'';
            var forf=g.forfeits?(' · <span class="gv-warn">'+g.forfeits+' forfeited</span>'):'';
            var extras=(g.hasRules?' · <span class="gv-flag">rules</span>':'')+skill+forf;
            var actions='<a class="gv-btn gv-btn-ghost" href="/giveaways/'+g.id+'/verify" target="_blank">Verify</a>';
            if(!g.drawnAt){actions='<button class="gv-btn gv-btn-ghost" data-act="edit" data-id="'+g.id+'">Edit</button>'
              +'<button class="gv-btn gv-btn-ghost" data-act="draw" data-id="'+g.id+'">Draw winner</button>'
              +'<button class="gv-btn gv-btn-ghost" data-act="tog" data-id="'+g.id+'">'+(g.active?'Close':'Reopen')+'</button>'+actions;}
            actions+='<button class="gv-btn gv-btn-x" data-act="del" data-id="'+g.id+'">Delete</button>';
            return '<div class="gv-row"><span class="gv-dot gv-'+status+'"></span>'
              +'<div class="gv-row-b"><div class="gv-row-t">'+esc(g.title)+' — '+esc(g.prize)+'</div>'
              +'<span class="gv-tag">'+g.entries+' entries'+win+extras+'</span>'
              +'<div class="gv-seed">'+seedLine+'</div></div>'
              +'<div class="gv-row-a">'+actions+'</div></div>';
          }).join('');
        });}
        var imgUrl='';
        var prev=document.getElementById('img-prev'),fileInput=document.getElementById('img-file'),clearBtn=document.getElementById('img-clear');
        function showImg(u){imgUrl=u||'';if(imgUrl){prev.style.backgroundImage="url('"+imgUrl+"')";prev.classList.add('has');clearBtn.hidden=false;}else{prev.style.backgroundImage='';prev.classList.remove('has');clearBtn.hidden=true;}}
        document.getElementById('img-btn').addEventListener('click',function(){fileInput.click();});
        clearBtn.addEventListener('click',function(){showImg('');});
        fileInput.addEventListener('change',function(){var f=fileInput.files[0];if(!f)return;var fd=new FormData();fd.append('file',f);notify('Uploading image…');
          fetch('/uploads/image',{method:'POST',headers:{'X-CSRF-TOKEN':csrf,Accept:'application/json'},credentials:'same-origin',body:fd})
            .then(function(r){return r.ok?r.json():null;}).then(function(d){if(d&&d.url){showImg(d.url);notify('Image uploaded');}else notify('Upload failed','error');})
            .catch(function(){notify('Upload failed','error');});fileInput.value='';});
        var FIELDS=['title','prize','description','rules','skill_question','skill_answer','skill_attempt_limit','ends_at'];
        function val(id){return (document.getElementById(id).value||'').trim();}
        function set(id,v){document.getElementById(id).value=v||'';}
        var editingId=null;
        function reset(){editingId=null;FIELDS.forEach(function(id){set(id,'');});showImg('');
          document.getElementById('form-h').textContent='New giveaway';
          document.getElementById('add').textContent='Create giveaway';
          document.getElementById('cancel').hidden=true;}
        function edit(id){fetch('/admin/ext/giveaways/'+id,{headers:H}).then(function(r){return r.ok?r.json():null;}).then(function(g){
          if(!g){notify('Could not load that giveaway','error');return;}
          editingId=g.id;
          set('title',g.title);set('prize',g.prize);set('description',g.description);set('rules',g.rules);
          set('skill_question',g.skill_question);set('skill_answer',g.skill_answer);
          set('skill_attempt_limit',g.skill_attempt_limit||0);set('ends_at',g.ends_at);
          showImg(g.image_path||'');
          document.getElementById('form-h').textContent='Editing: '+g.title;
          document.getElementById('add').textContent='Save changes';
          document.getElementById('cancel').hidden=false;
          document.getElementById('form-card').scrollIntoView({behavior:'smooth',block:'start'});});}
        function add(){var body={title:val('title'),prize:val('prize'),description:val('description'),
          rules:val('rules')||null,skill_question:val('skill_question')||null,skill_answer:val('skill_answer')||null,
          skill_attempt_limit:parseInt(val('skill_attempt_limit'),10)||0,
          image_path:imgUrl||null,ends_at:document.getElementById('ends_at').value||null};
          if(!body.title||!body.prize){notify('Title and prize are required','error');return;}
          if(body.skill_question&&!body.skill_answer){notify('Give the correct answer to the skill-testing question','error');return;}
          var editing=editingId;
          fetch('/admin/ext/giveaways'+(editing?'/'+editing:''),{method:'POST',headers:H,body:JSON.stringify(body)}).then(function(r){
            if(r.ok){reset();notify(editing?'Giveaway updated':'Giveaway created');load();}
            else notify(editing?'Could not save':'Could not create','error');});}
        document.getElementById('cancel').addEventListener('click',reset);
        function draw(id){if(!confirm('Draw a winner now? This closes the giveaway and reveals the seed.'))return;
          fetch('/admin/ext/giveaways/'+id+'/draw',{method:'POST',headers:H}).then(function(r){return r.json().then(function(d){return {ok:r.ok,d:d};});}).then(function(x){
            if(x.ok&&x.d.ok){notify('Winner: '+x.d.winner);if(String(editingId)===String(id))reset();}
            else{notify(x.d.message||'Could not draw','error');}load();});}
        function tog(id){fetch('/admin/ext/giveaways/'+id+'/toggle',{method:'POST',headers:H}).then(function(){load();});}
        function del(id){if(!confirm('Delete this giveaway? This removes its entries too.'))return;
          fetch('/admin/ext/giveaways/'+id,{method:'DELETE',headers:H}).then(function(){
            if(String(editingId)===String(id))reset();notify('Deleted');load();});}
        document.getElementById('add').addEventListener('click',add);
        document.getElementById('list').addEventListener('click',function(ev){var b=ev.target.closest('button[data-act]');if(!b)return;
          var id=b.getAttribute('data-id'),act=b.getAttribute('data-act');
          if(act==='edit')edit(id);else if(act==='draw')draw(id);else if(act==='tog')tog(id);else if(act==='del')del(id);});
        load();
        JS;
    }

    // --- Shared styling ----------------------------------------------------

    private static function css(): string
    {
        return <<<CSS
        .gv-wrap{max-width:900px;margin:0 auto;padding:24px 16px 64px}
        .gv-narrow{max-width:680px}
        .ext-embed .gv-wrap{padding:0}
        .gv-muted{color:rgb(var(--c-muted));font-size:14px}
        .gv-hero{padding:28px 30px;margin-bottom:22px;border-radius:18px;border:1px solid rgb(var(--c-border));
          background:linear-gradient(135deg,rgba(91,91,214,.16),rgba(139,92,246,.10)),rgb(var(--c-surface))}
        .gv-hero-sm{padding:22px 24px}
        .gv-eyebrow{font-size:13px;font-weight:800;letter-spacing:.04em;color:rgb(var(--c-primary));margin-bottom:6px}
        .gv-h1{font-size:1.9rem;font-weight:900;letter-spacing:-.02em;margin:0;color:rgb(var(--c-text))}
        .gv-h2{font-size:1.4rem;font-weight:800;margin:0 0 4px;color:rgb(var(--c-text))}
        .gv-h3{font-size:1.05rem;font-weight:800;margin:0 0 12px;color:rgb(var(--c-text))}
        .gv-sub{margin:8px 0 0;color:rgb(var(--c-text-2));font-size:14px;line-height:1.55;max-width:620px}
        .gv-sub a,.gv-crumbs a{color:rgb(var(--c-primary));font-weight:600;text-decoration:none}
        .gv-card{background:rgb(var(--c-surface));border:1px solid rgb(var(--c-border));border-radius:16px;padding:22px;margin-bottom:16px}
        .gv-active{border-color:rgb(var(--c-primary)/.5)}
        .gv-badge{display:inline-block;font-size:12px;font-weight:800;letter-spacing:.03em;color:rgb(var(--c-primary));
          background:rgb(var(--c-primary)/.10);padding:4px 10px;border-radius:999px;margin-bottom:12px}
        .gv-t{font-size:1.3rem;font-weight:800;margin:0 0 4px;color:rgb(var(--c-text))}
        .gv-prize{font-size:15px;color:rgb(var(--c-text-2));margin-bottom:8px}
        .gv-desc{color:rgb(var(--c-text-2));line-height:1.6;margin:0 0 10px}
        .gv-meta{font-size:13px;color:rgb(var(--c-muted));margin-bottom:14px}
        .gv-actions{display:flex;gap:8px}
        .gv-f{display:block;font-size:13px;font-weight:600;color:rgb(var(--c-text-2));margin:14px 0 5px}
        .gv-card input,.gv-card textarea{width:100%;font:inherit;font-size:14px;padding:10px 12px;border-radius:10px;
          border:1px solid rgb(var(--c-border));background:rgb(var(--c-surface-2));color:rgb(var(--c-text))}
        .gv-card input:focus,.gv-card textarea:focus{outline:none;border-color:rgb(var(--c-primary))}
        .gv-create{margin-top:16px}
        .gv-img-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
        .gv-img-prev{width:72px;height:72px;border-radius:10px;border:1px dashed rgb(var(--c-border));background:rgb(var(--c-surface-2)) center/cover no-repeat}
        .gv-img-prev.has{border-style:solid}
        .gv-card-img{width:100%;height:160px;border-radius:12px;margin-bottom:14px;background:rgb(var(--c-surface-2)) center/cover no-repeat}
        .gv-btn{font:inherit;font-size:13.5px;font-weight:700;padding:9px 15px;border-radius:10px;border:1px solid rgb(var(--c-border));
          background:rgb(var(--c-surface));color:rgb(var(--c-text));cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
        .gv-btn-p{background:rgb(var(--c-primary));border-color:rgb(var(--c-primary));color:#fff}
        .gv-btn-p:hover{filter:brightness(1.06)}
        .gv-btn-ghost{background:rgb(var(--c-surface-2))}
        .gv-btn-x{color:#e5484d;border-color:transparent;background:rgba(229,72,77,.08)}
        .gv-row{display:flex;align-items:center;gap:12px;padding:14px 0;border-bottom:1px solid rgb(var(--c-border))}
        .gv-row:last-child{border-bottom:0}
        .gv-row-b{flex:1;min-width:0}
        .gv-row-t{font-weight:700;color:rgb(var(--c-text))}
        .gv-row-m{font-size:13px;color:rgb(var(--c-muted));margin-top:2px}
        .gv-row-a{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end}
        .gv-tag{font-size:12px;color:rgb(var(--c-muted))}
        .gv-win{color:#10b981;font-weight:700}
        .gv-seed{font-family:ui-monospace,monospace;font-size:11px;color:rgb(var(--c-muted));word-break:break-all;margin-top:3px}
        .gv-dot{width:9px;height:9px;border-radius:50%;flex-shrink:0}
        .gv-on{background:#10b981}.gv-off{background:rgb(var(--c-muted))}.gv-drawn{background:rgb(var(--c-primary))}
        .gv-list{display:flex;flex-direction:column}
        .gv-row-b .gv-row-t{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        a.gv-row{display:flex;align-items:center;gap:12px;padding:13px 0;border-bottom:1px solid rgb(var(--c-border));text-decoration:none;color:inherit}
        a.gv-row:last-child{border-bottom:0}
        .gv-verify{font-size:13px;font-weight:700;color:rgb(var(--c-primary))}
        .gv-blank{text-align:center;padding:48px 20px;border:1.5px dashed rgb(var(--c-border));border-radius:16px}
        .gv-blank-ico{font-size:40px;margin-bottom:8px}
        .gv-blank-t{font-weight:800;color:rgb(var(--c-text))}
        .gv-crumbs{font-size:13px;color:rgb(var(--c-muted));margin-bottom:14px}
        .gv-crumbs span{margin:0 4px}
        .gv-how{font-size:13.5px;color:rgb(var(--c-text-2));line-height:1.6;margin:10px 0 16px}
        .gv-how code,.gv-code{font-family:ui-monospace,monospace}
        .gv-kv{margin:14px 0}
        .gv-k{display:block;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:rgb(var(--c-muted));margin-bottom:5px}
        .gv-code{display:block;font-size:12.5px;background:rgb(var(--c-surface-2));border:1px solid rgb(var(--c-border));border-radius:9px;padding:9px 11px;color:rgb(var(--c-text));word-break:break-all}
        .gv-note{font-size:12px;color:rgb(var(--c-muted))}
        .gv-win{color:#10b981;font-weight:700}
        .gv-pending{font-size:14px;color:rgb(var(--c-text-2));background:rgb(var(--c-surface-2));border-radius:10px;padding:12px 14px;margin:14px 0}
        .gv-check{margin-top:16px;font-size:13.5px;font-weight:600;padding:11px 13px;border-radius:10px;background:rgb(var(--c-surface-2));color:rgb(var(--c-text-2))}
        .gv-ok{background:rgba(16,185,129,.12);color:#059669}
        .gv-bad{background:rgba(229,72,77,.12);color:#e5484d}
        .gv-rules{margin:0 0 14px;border:1px solid rgb(var(--c-border));border-radius:12px;background:rgb(var(--c-surface-2))}
        .gv-rules-s{cursor:pointer;list-style:none;padding:11px 14px;font-size:13.5px;font-weight:700;color:rgb(var(--c-text-2))}
        .gv-rules-s::-webkit-details-marker{display:none}
        .gv-rules-s::after{content:'▾';float:right;font-size:11px;opacity:.6}
        .gv-rules[open] .gv-rules-s::after{content:'▴'}
        .gv-rules[open] .gv-rules-s{border-bottom:1px solid rgb(var(--c-border))}
        .gv-rules-body{white-space:pre-wrap;padding:12px 14px 14px;font-size:13.5px;line-height:1.65;color:rgb(var(--c-text-2));
          max-height:420px;overflow:auto;overflow-wrap:anywhere}
        .gv-skill{padding:12px 14px}
        .gv-skill+.gv-rules-body{border-top:1px solid rgb(var(--c-border))}
        .gv-skill-q{font-size:14px;font-weight:700;color:rgb(var(--c-text));margin:0 0 4px}
        .gv-help{font-size:12px;color:rgb(var(--c-muted));line-height:1.5;margin:6px 0 0}
        .gv-form-h{font-size:14px;font-weight:800;color:rgb(var(--c-text));padding-bottom:10px;border-bottom:1px solid rgb(var(--c-border))}
        .gv-create{display:flex;gap:8px;align-items:center}
        .gv-flag{color:rgb(var(--c-primary));font-weight:600}
        .gv-warn{color:#d97706;font-weight:600}
        .gv-won{border:1px solid rgb(var(--c-primary)/.45);border-radius:16px;padding:22px;margin-bottom:16px;
          background:linear-gradient(135deg,rgba(91,91,214,.14),rgba(139,92,246,.09)),rgb(var(--c-surface))}
        .gv-won-h{font-size:1.2rem;font-weight:800;color:rgb(var(--c-text));margin-bottom:4px}
        .gv-won-sub{font-size:14px;color:rgb(var(--c-text-2));margin:10px 0 12px}
        .gv-won .gv-skill-q{font-size:15px;font-weight:700;color:rgb(var(--c-text));margin-bottom:12px}
        .gv-won-row{display:flex;gap:8px;flex-wrap:wrap}
        .gv-won-row .gv-ans{flex:1;min-width:200px;font:inherit;font-size:14px;padding:10px 12px;border-radius:10px;
          border:1px solid rgb(var(--c-border));background:rgb(var(--c-surface-2));color:rgb(var(--c-text))}
        .gv-won-row .gv-ans:focus{outline:none;border-color:rgb(var(--c-primary))}
        .gv-won-msg:not(:empty){margin-top:12px;font-size:13.5px;font-weight:600;padding:10px 12px;border-radius:10px}
        .gv-won-msg.gv-ok{background:rgba(16,185,129,.12);color:#059669}
        .gv-won-msg.gv-bad{background:rgba(229,72,77,.12);color:#e5484d}
        .gv-won-lost{border-color:rgb(var(--c-border));background:rgb(var(--c-surface-2))}
        .gv-won-lost .gv-won-h{color:rgb(var(--c-text-2))}
        .gv-won-lost a{color:rgb(var(--c-primary));font-weight:600;text-decoration:none}
        .gv-attempts{font-size:12.5px;font-weight:600;color:#d97706;margin-bottom:10px}
        CSS;
    }
}
