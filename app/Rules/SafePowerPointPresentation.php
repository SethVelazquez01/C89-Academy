<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Translation\PotentiallyTranslatedString;
use ZipArchive;

class SafePowerPointPresentation implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile
            || strtolower($value->getClientOriginalExtension()) !== 'pptx') {
            $fail('El archivo debe ser una presentación de PowerPoint en formato .pptx.');

            return;
        }

        $archive = new ZipArchive;
        $opened = $archive->open($value->getRealPath());

        if ($opened !== true) {
            $fail('El archivo de PowerPoint no es válido o está dañado.');

            return;
        }

        try {
            if ($archive->numFiles > 5000
                || $archive->locateName('[Content_Types].xml') === false
                || $archive->locateName('ppt/presentation.xml') === false) {
                $fail('El archivo no contiene una presentación de PowerPoint válida.');

                return;
            }

            $contentTypes = $archive->getFromName('[Content_Types].xml', 1024 * 1024);

            if (! is_string($contentTypes)
                || ! str_contains(
                    $contentTypes,
                    'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml',
                )) {
                $fail('La presentación debe usar el formato .pptx estándar y no puede contener macros.');

                return;
            }

            $totalUncompressedBytes = 0;

            for ($index = 0; $index < $archive->numFiles; $index++) {
                $entry = $archive->statIndex($index);

                if (! is_array($entry)) {
                    $fail('No fue posible validar la estructura de la presentación.');

                    return;
                }

                $totalUncompressedBytes += $entry['size'];
                $entryName = strtolower(str_replace('\\', '/', $entry['name']));

                if ($entryName === 'ppt/vbaproject.bin'
                    || str_starts_with($entryName, 'ppt/activex/')) {
                    $fail('La presentación no puede contener macros ni controles activos.');

                    return;
                }

                if ($totalUncompressedBytes > 150 * 1024 * 1024) {
                    $fail('La presentación contiene demasiados datos para procesarse de forma segura.');

                    return;
                }
            }
        } finally {
            $archive->close();
        }
    }
}
