<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

namespace App\Http\Controllers;

use App\Models\Beatmap;
use App\Models\Country;
use App\Models\CountryStatistics;
use App\Models\Spotlight;
use App\Models\UserStatistics;
use DB;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @group Ranking
 */
class RankingController extends Controller
{
    private $country;
    private $params;
    private $friendsOnly;

    const PAGE_SIZE = 50;
    const MAX_RESULTS = 10000;
    const RANKING_TYPES = ['performance', 'charts', 'score', 'country'];
    const SPOTLIGHT_TYPES = ['charts'];

    public function __construct()
    {
        parent::__construct();

        $this->params = get_params(array_merge(request()->all(), request()->route()->parameters()), null, [
            'country', // overridden later for view
            'filter',
            'mode',
            'spotlight:int', // will be overriden by spotlight object for view
            'type',
        ]);

        $this->params['filter'] = $this->params['filter'] ?? 'all';
        $this->friendsOnly = $this->params['filter'] === 'friends';

        view()->share('hasPager', !in_array($this->params['type'], static::SPOTLIGHT_TYPES, true));
        view()->share('spotlight', null); // so variable capture in selector function doesn't die when spotlight is null.
        view()->share($this->params); // won't set null values

        $this->middleware(function ($request, $next) {
            $mode = $this->params['mode'];
            $type = $this->params['type'];

            if ($mode === null) {
                return ujs_redirect(route('rankings', ['mode' => 'osu', 'type' => 'performance']));
            }

            if (!Beatmap::isModeValid($mode)) {
                abort(404);
            }

            if ($type === null) {
                return ujs_redirect(route('rankings', ['mode' => $mode, 'type' => 'performance']));
            }

            if (!in_array($type, static::RANKING_TYPES, true)) {
                abort(404);
            }

            if (isset($this->params['country']) && $type === 'performance') {
                $countryStats = CountryStatistics::where('display', 1)
                    ->where('country_code', $this->params['country'])
                    ->first();

                if ($countryStats === null) {
                    return ujs_redirect(route('rankings', ['mode' => $mode, 'type' => $type]));
                }

                $this->country = $countryStats->country;
            }

            view()->share('country', $this->country);

            return $next($request);
        });
    }

    /**
     * Get Ranking
     *
     * Gets the current ranking for the specified type and game mode.
     *
     * ---
     *
     * ### Response Format
     *
     * Returns [Ranking Response](#ranking-response)
     *
     * ### Route Parameters
     *
     * Field  | Status   | Type
     * -------| ---------| -----------------
     * mode   | required | [GameMode](#gamemode)
     * type   | required | [RankingType](#rankingtype)
     *
     * @authenticated
     *
     * @queryParam spotlight The id of the spotlight if `type` is `charts`
     */
    public function index($mode, $type)
    {
        if ($type === 'charts') {
            return $this->spotlight($mode);
        }

        return with_db_fallback('mysql-readonly', function ($connection) use ($mode, $type) {
            $modeInt = Beatmap::modeInt($mode);

            if ($type === 'country') {
                $stats = CountryStatistics::where('display', 1)
                    ->with('country')
                    ->where('mode', $modeInt)
                    ->orderBy('performance', 'desc');
            } else {
                $class = UserStatistics\Model::getClass($mode);
                $table = (new $class)->getTable();
                $stats = $class
                    ::on($connection)
                    ->with(['user', 'user.country'])
                    ->whereHas('user', function ($userQuery) {
                        $userQuery->default();
                    });

                if ($this->friendsOnly) {
                    $stats->friendsOf(auth()->user());
                }

                if ($type === 'performance') {
                    if ($this->country !== null) {
                        $stats
                            ->where('country_acronym', $this->country['acronym'])
                            // preferrable to rank_score when filtering by country
                            ->from(DB::raw("{$table} FORCE INDEX (country_acronym_2)"));
                    } else {
                        // force to order by rank_score instead of sucking down entire users table first.
                        $stats->from(DB::raw("{$table} FORCE INDEX (rank_score)"));
                    }

                    $stats->orderBy('rank_score', 'desc');
                } else { // 'score'
                    $stats
                        // force to order by ranked_score instead of sucking down entire users table first.
                        ->from(DB::raw("{$table} FORCE INDEX (ranked_score)"))
                        ->orderBy('ranked_score', 'desc');
                }

                if (is_api_request()) {
                    $stats->with(['user.userProfileCustomization']);
                }
            }

            $maxResults = $this->maxResults($modeInt);
            $maxPages = ceil($maxResults / static::PAGE_SIZE);
            // TODO: less repeatedly getting params out of request.
            $page = clamp(get_int(request('cursor.page') ?? request('page')), 1, $maxPages);

            $stats = $stats->limit(static::PAGE_SIZE)
                ->offset(static::PAGE_SIZE * ($page - 1))
                ->get();

            if (is_api_request()) {
                switch ($type) {
                    case 'country':
                        $ranking = json_collection($stats, 'CountryStatistics', ['country']);
                        break;

                    default:
                        $ranking = json_collection($stats, 'UserStatistics', ['user', 'user.cover', 'user.country']);
                        break;
                }

                return [
                    // TODO: switch to offset?
                    'cursor' => empty($ranking) || ($page >= $maxPages) ? null : ['page' => $page + 1],
                    'ranking' => $ranking,
                    'total' => $maxResults,
                ];
            }

            $scores = new LengthAwarePaginator(
                $stats,
                $maxPages * static::PAGE_SIZE,
                static::PAGE_SIZE,
                $page,
                ['path' => route('rankings', ['mode' => $mode, 'type' => $type])]
            );

            $countries = json_collection($this->getCountries($mode), 'Country');

            return ext_view("rankings.{$type}", compact('countries', 'scores'));
        });
    }

    public function spotlight($mode)
    {
        $chartId = $this->params['spotlight'] ?? null;

        $spotlights = Spotlight::orderBy('chart_id', 'desc')->get();
        if ($chartId === null) {
            $spotlight = $spotlights->first();
        } else {
            $spotlight = Spotlight::findOrFail($chartId);
        }

        if ($spotlight->hasMode($mode)) {
            $beatmapsets = $spotlight->beatmapsets($mode)->with('beatmaps')->get();
            $scores = $spotlight->ranking($mode);

            if ($this->friendsOnly) {
                $scores->friendsOf(auth()->user());
            }

            if (is_api_request()) {
                $scores = $scores->with(['user.userProfileCustomization'])->get();

                return [
                    // transformer can't do nested includes with params properly.
                    // https://github.com/thephpleague/fractal/issues/239
                    'beatmapsets' => json_collection($beatmapsets, 'Beatmapset', ['beatmaps']),
                    'ranking' => json_collection($scores, 'UserStatistics', ['user', 'user.cover', 'user.country']),
                    'spotlight' => json_item($spotlight, 'Spotlight', ["participant_count:mode({$mode})"]),
                ];
            } else {
                $scores = $scores->get();
                $scoreCount = $spotlight->participantCount($mode);
            }
        } else {
            if (is_api_request()) {
                abort(404);
            }

            $beatmapsets = collect();
            $scores = collect();
            $scoreCount = 0;
        }

        $selectOptions = [
            'selected' => $this->optionFromSpotlight($spotlight),
            'options' => $spotlights->map(function ($s) {
                return $this->optionFromSpotlight($s);
            }),
        ];

        return ext_view(
            'rankings.charts',
            compact('scores', 'scoreCount', 'selectOptions', 'spotlight', 'beatmapsets')
        );
    }

    private function getCountries(string $mode)
    {
        $relation = 'statistics'.title_case($mode);

        return Country::where('display', true)->whereHas($relation, function ($query) {
            $query->where('display', true);
        })->get();
    }

    private function optionFromSpotlight(Spotlight $spotlight): array
    {
        return ['id' => $spotlight->chart_id, 'text' => $spotlight->name];
    }

    private function maxResults($modeInt)
    {
        if ($this->params['type'] === 'country') {
            return CountryStatistics::where('display', 1)
                ->where('mode', $modeInt)
                ->count();
        }

        return min(
            $this->country !== null ? $this->country->usercount : static::MAX_RESULTS,
            static::MAX_RESULTS
        );
    }
}
