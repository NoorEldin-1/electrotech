<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Delivery;

use App\Http\Api\ApiQuery;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Delivery\StoreSiteSurveyRequest;
use App\Http\Requests\Api\V1\Delivery\UpdateSiteSurveyRequest;
use App\Http\Resources\Api\V1\Delivery\SiteSurveyResource;
use App\Models\SiteSurvey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * @group 28. Site surveys
 *
 * معاينة الموقع — the visit that precedes an installation.
 *
 * The one thing worth knowing: `measurements` is free text, not a structured
 * field. What a surveyor needs to write down differs by site and by product,
 * and a schema that fitted the last ten sites would silently lose the
 * eleventh. Attach photographs through the generic attachments endpoint with
 * `owner_type=project`.
 */
class SiteSurveyController extends ApiController
{
    /**
     * List site surveys
     *
     * @authenticated
     *
     * @queryParam page integer The page number. Example: 1
     * @queryParam per_page integer Rows per page, max 100. Example: 25
     * @queryParam search string Matches the measurements text. Example: busbar
     * @queryParam filter[project] integer Operation id. Example: 4
     * @queryParam filter[surveyed_by] integer User id. Example: 9
     * @queryParam filter[survey_date] string Inclusive date range from,until. Example: 2026-01-01,2026-06-30
     * @queryParam sort string Allowed: survey_date, created_at. Example: -survey_date
     * @queryParam include string Allowed: project. Example: project
     *
     * @response 200 scenario="Success" {"data":[{"id":11,"type":"site_survey","project_id":4,"survey_date":"2026-09-01","measurements":"Riser 4.2m, clearance 800mm"}],"meta":{"request_id":"9f1c","api_version":"1","pagination":{"total":1,"count":1,"per_page":25,"current_page":1,"total_pages":1}},"links":{"first":"","prev":null,"next":null,"last":""}}
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SiteSurvey::class);

        $surveys = ApiQuery::for(SiteSurvey::query(), $request)
            ->allowFilters([
                'project' => ApiQuery::exact('project_id'),
                'surveyed_by' => ApiQuery::exact('surveyed_by'),
                'survey_date' => ApiQuery::dateBetween('survey_date'),
            ])
            ->allowSearch(['measurements'])
            ->allowSorts(['survey_date', 'created_at'])
            ->allowIncludes(['project'])
            ->defaultSort('-survey_date')
            ->paginate();

        return $this->respondPaginated(SiteSurveyResource::collection($surveys));
    }

    /**
     * Show a site survey
     *
     * @authenticated
     *
     * @urlParam site_survey integer required The survey id. Example: 11
     *
     * @response 200 scenario="Success" {"data":{"id":11,"type":"site_survey","survey_date":"2026-09-01","measurements":"Riser 4.2m, clearance 800mm","notes":"Access via the service lift only"},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function show(SiteSurvey $siteSurvey): JsonResponse
    {
        $this->authorize('view', $siteSurvey);

        return $this->respond(new SiteSurveyResource($siteSurvey->load('project')));
    }

    /**
     * Record a site survey
     *
     * The surveyor defaults to the authenticated user, which is the common
     * case: the person filing the survey is the person who did it.
     *
     * @authenticated
     *
     * @bodyParam project_id integer required The operation surveyed. Example: 4
     * @bodyParam survey_date date optional Defaults to today. Example: 2026-09-01
     * @bodyParam measurements string optional Free text — see the group note. Example: Riser 4.2m, clearance 800mm
     * @bodyParam notes string optional Example: Access via the service lift only
     * @bodyParam surveyed_by integer optional Defaults to the caller. Example: 9
     *
     * @response 201 scenario="Created" {"data":{"id":11,"type":"site_survey","project_id":4,"survey_date":"2026-09-01"},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function store(StoreSiteSurveyRequest $request): JsonResponse
    {
        $this->authorize('create', SiteSurvey::class);

        $survey = SiteSurvey::create([
            'project_id' => $request->integer('project_id'),
            'survey_date' => $request->input('survey_date', now()->toDateString()),
            'measurements' => $request->input('measurements'),
            'notes' => $request->input('notes'),
            'surveyed_by' => $request->input('surveyed_by', Auth::id()),
        ]);

        return $this->respondCreated(new SiteSurveyResource($survey->load('project')));
    }

    /**
     * Update a site survey
     *
     * @authenticated
     *
     * @urlParam site_survey integer required The survey id. Example: 11
     *
     * @bodyParam measurements string optional Example: Riser 4.2m, clearance 750mm (re-measured)
     * @bodyParam notes string optional Example: Second visit
     *
     * @response 200 scenario="Updated" {"data":{"id":11,"type":"site_survey","measurements":"Riser 4.2m, clearance 750mm (re-measured)"},"meta":{"request_id":"9f1c","api_version":"1"}}
     */
    public function update(UpdateSiteSurveyRequest $request, SiteSurvey $siteSurvey): JsonResponse
    {
        $this->authorize('update', $siteSurvey);

        $siteSurvey->update($request->validated());

        return $this->respond(new SiteSurveyResource($siteSurvey->fresh()->load('project')));
    }

    /**
     * Delete a site survey
     *
     * @authenticated
     *
     * @urlParam site_survey integer required The survey id. Example: 11
     *
     * @response 204 scenario="Deleted" {}
     */
    public function destroy(SiteSurvey $siteSurvey): JsonResponse
    {
        $this->authorize('delete', $siteSurvey);

        $siteSurvey->delete();

        return $this->respondNoContent();
    }
}
