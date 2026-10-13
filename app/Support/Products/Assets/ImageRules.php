<?php

namespace App\Support\Products\Assets;

/**
 * Request validation of an image upload (E-35). It only checks that a file arrived within the size
 * bound; the type and the dimensions are decided by decoding it in `ImageProcessor`, never by the
 * name or the declared MIME type.
 */
final class ImageRules
{
    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return ['image' => ['required', 'file', 'max:'.AssetLimits::MAX_KILOBYTES]];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'image.required' => __('validation.image_required'),
            'image.file' => __('validation.image_upload_failed'),
            'image.uploaded' => __('validation.image_upload_failed'),
            'image.max' => __('validation.image_size'),
        ];
    }
}
