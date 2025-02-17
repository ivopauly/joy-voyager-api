<?php

declare(strict_types=1);

namespace Joy\VoyagerApi\Http\Traits;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use TCG\Voyager\Events\BreadDataUpdated;
use TCG\Voyager\Facades\Voyager;

trait UpdateAction
{
    // POST BR(E)AD
    public function update(Request $request, $id)
    {
        $slug = $this->getSlug($request);

        $dataType = Voyager::model('DataType')->where('slug', '=', $slug)->first();

        // Compatibility with Model binding.
        $id = $id instanceof \Illuminate\Database\Eloquent\Model ? $id->{$id->getKeyName()} : $id;

        $model = app($dataType->model_name);
        $query = $model->query();
        if ($dataType->scope && $dataType->scope != '' && method_exists($model, 'scope' . ucfirst($dataType->scope))) {
            $query = $query->{$dataType->scope}();
        }
        if ($model && in_array(SoftDeletes::class, class_uses_recursive($model))) {
            $query = $query->withTrashed();
        }

        $data = $query->findOrFail($id);

        // Check permission
        $this->authorize('edit', $data);

        // Validate fields with ajax
        $val = $this->validateBread($request->all(), $dataType->editRows, $dataType->name, $id)->validate();

        // Use dry-run only to validate
        if ($request->has('dry-run')) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        // Get fields with images to remove before updating and make a copy of $data
        $to_remove = $dataType->editRows->where('type', 'image')
            ->filter(function ($item, $key) use ($request) {
                return $request->hasFile($item->field);
            });
        $original_data = clone($data);

        $this->insertUpdateData($request, $slug, $dataType->editRows, $data);

        // Delete Images
        $this->deleteBreadImages($original_data, $to_remove);

        event(new BreadDataUpdated($dataType, $data));

        $response = $this->overrideSendUpdateResponse(
            $request,
            $data
        );
        if ($response) {
            return $response;
        }

        $resourceClass = 'joy-voyager-api.json';

        if (app()->bound("joy-voyager-api.$slug.json")) {
            $resourceClass = "joy-voyager-api.$slug.json";
        }

        $resource = app()->make($resourceClass);

        return $resource::make($data)
            ->additional(
                [
                    'message' => __('voyager::generic.successfully_updated')
                        . " {$dataType->getTranslatedAttribute('display_name_singular')}",
                    'alert-type' => 'success',
                    'canBrowse'  => auth()->user()->can(
                        'browse',
                        app($dataType->model_name)
                    ),
                ]
            );
    }

    /**
     * Override send Update response.
     *
     * @param Request $request Request
     * @param mixed   $data    Data
     *
     * @return mixed
     */
    protected function overrideSendUpdateResponse(
        Request $request,
        $data
    ) {
        //
    }
}
