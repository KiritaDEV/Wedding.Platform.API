<?php

namespace App\Http\Controllers;

use App\Actions\Websites\AddWebsiteProjectColor;
use App\Actions\Websites\CreateWebsiteProject;
use App\Actions\Websites\CreateWebsiteSection;
use App\Actions\Websites\DeleteWebsiteSection;
use App\Actions\Websites\DuplicateWebsiteSection;
use App\Actions\Websites\InitializeEventWebsite;
use App\Actions\Websites\RenameWebsiteSection;
use App\Actions\Websites\ReorderWebsiteSections;
use App\Actions\Websites\SetWebsiteSectionEnabled;
use App\Actions\Websites\UpdateWebsiteDesignSettings;
use App\Actions\Websites\UpdateWebsiteSectionAppearance;
use App\Actions\Websites\UpdateWebsiteSectionContent;
use App\Actions\Websites\UpdateWebsiteSectionDesignDefaults;
use App\Exceptions\IncompatibleWebsiteTemplate;
use App\Exceptions\UnknownWebsiteTemplate;
use App\Exceptions\WebsiteAlreadyInitialized;
use App\Http\Requests\AddWebsiteProjectColorRequest;
use App\Http\Requests\CreateWebsiteProjectRequest;
use App\Http\Requests\CreateWebsiteSectionRequest;
use App\Http\Requests\InitializeWebsiteRequest;
use App\Http\Requests\RenameWebsiteSectionRequest;
use App\Http\Requests\ReorderWebsiteSectionsRequest;
use App\Http\Requests\UpdateWebsiteDesignSettingsRequest;
use App\Http\Requests\UpdateWebsiteSectionAppearanceRequest;
use App\Http\Requests\UpdateWebsiteSectionContentRequest;
use App\Http\Requests\UpdateWebsiteSectionDesignDefaultsRequest;
use App\Http\Requests\UpdateWebsiteSectionEnabledRequest;
use App\Http\Requests\UpdateWebsiteSectionPresentationRequest;
use App\Http\Resources\WebsiteDraftResource;
use App\Http\Resources\WebsiteProjectResource;
use App\Models\Event;
use App\Models\Website;
use App\Models\WebsiteSection;
use App\Website\WebsiteCreationTemplateCatalog;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class WebsiteDraftController extends Controller
{
    public function show(string $event): WebsiteDraftResource
    {
        return $this->draft($this->legacyWebsite($this->authorizedEvent($event)));
    }

    public function projects(string $event): AnonymousResourceCollection
    {
        $eventModel = $this->authorizedEvent($event);

        return WebsiteProjectResource::collection(
            $eventModel->websiteProjects()->orderBy('created_at')->orderBy('id')->get(),
        );
    }

    public function storeProject(
        CreateWebsiteProjectRequest $request,
        CreateWebsiteProject $createProject,
        string $event,
    ): WebsiteDraftResource|JsonResponse {
        $eventModel = $this->authorizedEvent($event);

        try {
            $website = $createProject->handle(
                $eventModel,
                $request->validated('name'),
                $request->validated('templateKey'),
            );
        } catch (UnknownWebsiteTemplate|IncompatibleWebsiteTemplate $exception) {
            throw ValidationException::withMessages(['templateKey' => $exception->getMessage()]);
        }

        return $this->draft($website)->response()->setStatusCode(201);
    }

    public function showProject(string $event, string $website): WebsiteDraftResource
    {
        $eventModel = $this->authorizedEvent($event);

        return $this->draft($this->website($eventModel, $website));
    }

    public function addProjectColor(
        AddWebsiteProjectColorRequest $request,
        AddWebsiteProjectColor $addColor,
        string $event,
        string $website,
    ): JsonResponse {
        $eventModel = $this->authorizedEvent($event);
        $project = $addColor->handle($this->website($eventModel, $website), $request->validated('value'));

        return $this->draft($project)->response()->setStatusCode(201);
    }

    public function store(
        InitializeWebsiteRequest $request,
        InitializeEventWebsite $initializeWebsite,
        string $event,
    ): WebsiteDraftResource|JsonResponse {
        $eventModel = $this->authorizedEvent($event);

        try {
            $website = $initializeWebsite->handle($eventModel, $request->validated('templateKey'));
        } catch (WebsiteAlreadyInitialized $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (UnknownWebsiteTemplate|IncompatibleWebsiteTemplate $exception) {
            throw ValidationException::withMessages(['templateKey' => $exception->getMessage()]);
        }

        return $this->draft($website)->response()->setStatusCode(201);
    }

    public function creationTemplates(WebsiteCreationTemplateCatalog $catalog, string $event): JsonResponse
    {
        $eventModel = $this->authorizedEvent($event);

        return response()->json(['data' => array_values(array_map(
            fn ($template): array => [
                'key' => $template->key,
                'displayName' => $template->displayName,
                'description' => $template->description,
                'styleTags' => $template->styleTags,
            ],
            $catalog->forEventType($eventModel->type),
        ))]);
    }

    public function updateDesign(
        UpdateWebsiteDesignSettingsRequest $request,
        UpdateWebsiteDesignSettings $updateDesign,
        string $event,
    ): WebsiteDraftResource {
        $website = $this->legacyWebsite($this->authorizedEvent($event));

        return $this->applyDesign($request, $updateDesign, $website);
    }

    public function updateProjectDesign(
        UpdateWebsiteDesignSettingsRequest $request,
        UpdateWebsiteDesignSettings $updateDesign,
        string $event,
        string $website,
    ): WebsiteDraftResource {
        $eventModel = $this->authorizedEvent($event);

        return $this->applyDesign($request, $updateDesign, $this->website($eventModel, $website));
    }

    private function applyDesign(
        UpdateWebsiteDesignSettingsRequest $request,
        UpdateWebsiteDesignSettings $updateDesign,
        Website $website,
    ): WebsiteDraftResource {
        $updateDesign->handle($website, $request->validated('designSettings'));

        return $this->draft($website);
    }

    public function updateSection(
        UpdateWebsiteSectionContentRequest $request,
        UpdateWebsiteSectionContent $updateContent,
        string $event,
        string $section,
    ): WebsiteDraftResource {
        $website = $this->legacyWebsite($this->authorizedEvent($event));

        return $this->applySectionContent($request, $updateContent, $website, $section);
    }

    public function updateProjectSection(
        UpdateWebsiteSectionContentRequest $request,
        UpdateWebsiteSectionContent $updateContent,
        string $event,
        string $website,
        string $section,
    ): WebsiteDraftResource {
        $eventModel = $this->authorizedEvent($event);

        return $this->applySectionContent($request, $updateContent, $this->website($eventModel, $website), $section);
    }

    private function applySectionContent(
        UpdateWebsiteSectionContentRequest $request,
        UpdateWebsiteSectionContent $updateContent,
        Website $website,
        string $section,
    ): WebsiteDraftResource {
        $sectionModel = $this->section($website, $section);
        $updateContent->handle($sectionModel, $request->validated('content'));

        return $this->draft($website);
    }

    public function updateSectionAppearance(
        UpdateWebsiteSectionAppearanceRequest $request,
        UpdateWebsiteSectionAppearance $updateAppearance,
        string $event,
        string $section,
    ): WebsiteDraftResource {
        $website = $this->legacyWebsite($this->authorizedEvent($event));

        return $this->applySectionAppearance($request, $updateAppearance, $website, $section);
    }

    public function updateProjectSectionAppearance(
        UpdateWebsiteSectionAppearanceRequest $request,
        UpdateWebsiteSectionAppearance $updateAppearance,
        string $event,
        string $website,
        string $section,
    ): WebsiteDraftResource {
        $eventModel = $this->authorizedEvent($event);

        return $this->applySectionAppearance($request, $updateAppearance, $this->website($eventModel, $website), $section);
    }

    private function applySectionAppearance(
        UpdateWebsiteSectionAppearanceRequest $request,
        UpdateWebsiteSectionAppearance $updateAppearance,
        Website $website,
        string $section,
    ): WebsiteDraftResource {
        $sectionModel = $this->section($website, $section);
        $updateAppearance->handle($sectionModel, $request->validated('appearance'));

        return $this->draft($website);
    }

    public function updateProjectSectionPresentation(
        UpdateWebsiteSectionPresentationRequest $request,
        UpdateWebsiteSectionContent $updateContent,
        UpdateWebsiteSectionAppearance $updateAppearance,
        string $event,
        string $website,
        string $section,
    ): WebsiteDraftResource {
        $eventModel = $this->authorizedEvent($event);
        $project = $this->website($eventModel, $website);
        $sectionModel = $this->section($project, $section);
        DB::transaction(function () use ($request, $updateContent, $updateAppearance, $sectionModel): void {
            $updateContent->handle($sectionModel, $request->validated('content'), false);
            $sectionModel->refresh();
            $updateAppearance->handle($sectionModel, $request->validated('appearance'));
        });

        return $this->draft($project);
    }

    public function updateSectionDesignDefaults(
        UpdateWebsiteSectionDesignDefaultsRequest $request,
        UpdateWebsiteSectionDesignDefaults $updateDesignDefaults,
        string $event,
        string $section,
    ): WebsiteDraftResource {
        $website = $this->legacyWebsite($this->authorizedEvent($event));

        return $this->applySectionDesignDefaults($request, $updateDesignDefaults, $website, $section);
    }

    public function updateProjectSectionDesignDefaults(
        UpdateWebsiteSectionDesignDefaultsRequest $request,
        UpdateWebsiteSectionDesignDefaults $updateDesignDefaults,
        string $event,
        string $website,
        string $section,
    ): WebsiteDraftResource {
        $eventModel = $this->authorizedEvent($event);

        return $this->applySectionDesignDefaults($request, $updateDesignDefaults, $this->website($eventModel, $website), $section);
    }

    private function applySectionDesignDefaults(
        UpdateWebsiteSectionDesignDefaultsRequest $request,
        UpdateWebsiteSectionDesignDefaults $updateDesignDefaults,
        Website $website,
        string $section,
    ): WebsiteDraftResource {
        $sectionModel = $this->section($website, $section);
        $updateDesignDefaults->handle($sectionModel, $request->validated('designDefaults'));

        return $this->draft($website);
    }

    public function updateSectionEnabled(
        UpdateWebsiteSectionEnabledRequest $request,
        SetWebsiteSectionEnabled $setEnabled,
        string $event,
        string $section,
    ): WebsiteDraftResource {
        $website = $this->legacyWebsite($this->authorizedEvent($event));

        return $this->applySectionEnabled($request, $setEnabled, $website, $section);
    }

    public function updateProjectSectionEnabled(
        UpdateWebsiteSectionEnabledRequest $request,
        SetWebsiteSectionEnabled $setEnabled,
        string $event,
        string $website,
        string $section,
    ): WebsiteDraftResource {
        $eventModel = $this->authorizedEvent($event);

        return $this->applySectionEnabled($request, $setEnabled, $this->website($eventModel, $website), $section);
    }

    private function applySectionEnabled(
        UpdateWebsiteSectionEnabledRequest $request,
        SetWebsiteSectionEnabled $setEnabled,
        Website $website,
        string $section,
    ): WebsiteDraftResource {
        $sectionModel = $this->section($website, $section);

        try {
            $setEnabled->handle($sectionModel, $request->boolean('isEnabled'));
        } catch (UnknownWebsiteTemplate|IncompatibleWebsiteTemplate $exception) {
            throw ValidationException::withMessages(['isEnabled' => $exception->getMessage()]);
        }

        return $this->draft($website);
    }

    public function reorder(
        ReorderWebsiteSectionsRequest $request,
        ReorderWebsiteSections $reorderSections,
        string $event,
    ): WebsiteDraftResource {
        $website = $this->legacyWebsite($this->authorizedEvent($event));

        return $this->applyReorder($request, $reorderSections, $website);
    }

    public function reorderProjectSections(
        ReorderWebsiteSectionsRequest $request,
        ReorderWebsiteSections $reorderSections,
        string $event,
        string $website,
    ): WebsiteDraftResource {
        $eventModel = $this->authorizedEvent($event);

        return $this->applyReorder($request, $reorderSections, $this->website($eventModel, $website));
    }

    private function applyReorder(
        ReorderWebsiteSectionsRequest $request,
        ReorderWebsiteSections $reorderSections,
        Website $website,
    ): WebsiteDraftResource {
        $reorderSections->handle($website, $request->validated('sectionIds'));

        return $this->draft($website);
    }

    public function createSection(CreateWebsiteSectionRequest $request, CreateWebsiteSection $create, string $event): JsonResponse
    {
        return $this->applyCreateSection($create, $this->legacyWebsite($this->authorizedEvent($event)), $request->validated('type'));
    }

    public function createProjectSection(CreateWebsiteSectionRequest $request, CreateWebsiteSection $create, string $event, string $website): JsonResponse
    {
        return $this->applyCreateSection($create, $this->website($this->authorizedEvent($event), $website), $request->validated('type'));
    }

    private function applyCreateSection(CreateWebsiteSection $create, Website $website, string $type): JsonResponse
    {
        $create->handle($website, $type);

        return $this->draft($website)->response()->setStatusCode(201);
    }

    public function deleteSection(DeleteWebsiteSection $delete, string $event, string $section): WebsiteDraftResource
    {
        return $this->applyDeleteSection($delete, $this->legacyWebsite($this->authorizedEvent($event)), $section);
    }

    public function deleteProjectSection(DeleteWebsiteSection $delete, string $event, string $website, string $section): WebsiteDraftResource
    {
        return $this->applyDeleteSection($delete, $this->website($this->authorizedEvent($event), $website), $section);
    }

    private function applyDeleteSection(DeleteWebsiteSection $delete, Website $website, string $section): WebsiteDraftResource
    {
        $delete->handle($this->section($website, $section));

        return $this->draft($website);
    }

    public function duplicateSection(DuplicateWebsiteSection $duplicate, string $event, string $section): JsonResponse
    {
        return $this->applyDuplicateSection($duplicate, $this->legacyWebsite($this->authorizedEvent($event)), $section);
    }

    public function duplicateProjectSection(DuplicateWebsiteSection $duplicate, string $event, string $website, string $section): JsonResponse
    {
        return $this->applyDuplicateSection($duplicate, $this->website($this->authorizedEvent($event), $website), $section);
    }

    private function applyDuplicateSection(DuplicateWebsiteSection $duplicate, Website $website, string $section): JsonResponse
    {
        $duplicate->handle($this->section($website, $section));

        return $this->draft($website)->response()->setStatusCode(201);
    }

    public function renameSection(RenameWebsiteSectionRequest $request, RenameWebsiteSection $rename, string $event, string $section): WebsiteDraftResource
    {
        return $this->applyRenameSection($rename, $this->legacyWebsite($this->authorizedEvent($event)), $section, $request->validated('editorName'));
    }

    public function renameProjectSection(RenameWebsiteSectionRequest $request, RenameWebsiteSection $rename, string $event, string $website, string $section): WebsiteDraftResource
    {
        return $this->applyRenameSection($rename, $this->website($this->authorizedEvent($event), $website), $section, $request->validated('editorName'));
    }

    private function applyRenameSection(RenameWebsiteSection $rename, Website $website, string $section, string $editorName): WebsiteDraftResource
    {
        $rename->handle($this->section($website, $section), $editorName);

        return $this->draft($website);
    }

    private function authorizedEvent(string $event): Event
    {
        $model = Event::query()->findOrFail($event);
        Gate::authorize('view', $model);

        return $model;
    }

    private function section(Website $website, string $section): WebsiteSection
    {
        return $website->sections()->findOrFail($section);
    }

    private function legacyWebsite(Event $event): Website
    {
        return $this->legacyWebsiteOrNull($event)
            ?? throw (new ModelNotFoundException)->setModel(Website::class);
    }

    private function legacyWebsiteOrNull(Event $event): ?Website
    {
        $websites = $event->websiteProjects()->limit(2)->get();

        if ($websites->count() > 1) {
            abort(409, 'This Event has multiple Website Projects. Use a project-specific route.');
        }

        return $websites->first();
    }

    private function website(Event $event, string $website): Website
    {
        return $event->websiteProjects()->findOrFail($website);
    }

    private function draft(Website $website): WebsiteDraftResource
    {
        return new WebsiteDraftResource($website->refresh()->load('sections.website'));
    }
}
