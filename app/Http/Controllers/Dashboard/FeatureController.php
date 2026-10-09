<?php

namespace App\Http\Controllers\Dashboard;
use App\Interfaces\FeatureRepositoryInterface;
use App\Models\Feature;
use App\Http\Requests\Dashboard\About\StoreFeatureRequest;
use App\Http\Traits\UploadImageTrait;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class FeatureController extends Controller
{
    use UploadImageTrait;
    private FeatureRepositoryInterface $featureRepository;

    public function __construct(FeatureRepositoryInterface $featureRepository) 
    {
        $this->middleware('permission:feature-list', ['only' => ['index','show']]);
        $this->middleware('permission:feature-create', ['only' => ['create','store']]);
        $this->middleware('permission:feature-edit', ['only' => ['update','edit']]);
        $this->middleware('permission:feature-delete', ['only' => ['destroy','delete_all','deleteAll']]);
        $this->featureRepository = $featureRepository;
    }


    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $features = $this->featureRepository->getAllFeatures($request);
        return view('admin.features.index', compact('features'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $feature = new Feature() ;
        return view('admin.features.create', compact('feature'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \App\Http\Requests\StoreFeatureRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function store(StoreFeatureRequest $request)
    {
        $featureDetails = $request->except('_token','_method','_desktop_command','idempotency_key');

        $feature = $this->featureRepository->createFeature($featureDetails);
        
        return redirect('admin/features')->with('success',trans('messages.AddSuccessfully'));
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Feature  $feature
     * @return \Illuminate\Http\Response
     */
    public function show(Feature $feature)
    {
        return view('admin.features.show', compact('feature'));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Feature  $feature
     * @return \Illuminate\Http\Response
     */
    public function edit(Feature $feature)
    {
        return view('admin.features.edit', compact('feature'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \App\Http\Requests\UpdateAboutRequest  $request
     * @param  \App\Models\Feature  $feature
     * @return \Illuminate\Http\Response
     */
    public function update(StoreFeatureRequest $request, Feature $feature)
    {
        $featureDetails = $request->except('_token','_method','_desktop_command','idempotency_key');
        $this->featureRepository->updateFeature($feature->id, $featureDetails);
        
        return redirect()->back()->with('success',trans('messages.UpdateSuccessfully'));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Feature  $feature
     * @return \Illuminate\Http\Response
     */
    public function destroy(Feature $feature)
    {
        $this->featureRepository->deleteFeature($feature->id);
      
        return redirect('admin/features')->with('success',trans('messages.DeleteSuccessfully'));
        
    }
    public function deleteAll(Request $request)
    {
        $ids = $request->ids;
        
        $this->featureRepository->deleteAllFeatures($ids);
        return response()->json(['success'=> trans('messages.RecordsDeleteSuccessfully')]);

    }
}