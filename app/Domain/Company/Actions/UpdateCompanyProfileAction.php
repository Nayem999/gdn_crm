<?php

namespace App\Domain\Company\Actions;

use App\Domain\Company\DTOs\CompanyProfileData;
use App\Domain\Company\Models\Company;
use Illuminate\Http\UploadedFile;

class UpdateCompanyProfileAction
{
    public function __invoke(Company $company, CompanyProfileData $data, ?UploadedFile $logo = null): Company
    {
        $company->update($data->toArray());

        if ($logo !== null) {
            $company->addMedia($logo)->toMediaCollection('logo');
        }

        return $company->refresh();
    }
}
