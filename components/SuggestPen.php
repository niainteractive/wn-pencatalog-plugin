<?php namespace Niainteractive\PenCatalog\Components;

use Cms\Classes\ComponentBase;
use Niainteractive\PenCatalog\Classes\MemberAuth;
use Niainteractive\PenCatalog\Models\Suggestion;
use Carbon\Carbon;
use Crypt;
use Request;
use Validator;
use ValidationException;

/**
 * "Suggest a pen" form.
 *
 * Members ask for a pen the catalogue does not have. Nothing is created in the
 * catalogue: suggestions land in a backend queue, which doubles as a record of
 * demand for the next batch of pens to add.
 */
class SuggestPen extends ComponentBase
{
    public function componentDetails()
    {
        return [
            'name'        => 'Suggest a pen',
            'description' => 'Lets a signed-in member ask for a pen the catalogue does not list.',
        ];
    }

    public function onRun()
    {
        $this->page['formToken'] = Crypt::encryptString((string) time());
        $this->page['member'] = MemberAuth::user();
    }

    public function onSend()
    {
        $user = MemberAuth::user();

        if (!$user) {
            throw new ValidationException(['model' => 'Please sign in to suggest a pen.']);
        }

        $data = post();

        // Honeypot and time trap, same controls as the contact form.
        if (!empty($data['website'])) {
            return $this->successResponse();
        }

        try {
            $sentAt = (int) Crypt::decryptString((string) ($data['_ts'] ?? ''));
        }
        catch (\Throwable $e) {
            $sentAt = 0;
        }

        $elapsed = time() - $sentAt;

        if ($sentAt <= 0 || $elapsed < 2 || $elapsed > 7200) {
            throw new ValidationException(['model' => 'That form expired. Please try again.']);
        }

        $validation = Validator::make($data, [
            'make'  => 'nullable|between:1,120',
            'model' => 'required|between:2,160',
            'url'   => 'nullable|url|max:500',
            'notes' => 'nullable|max:2000',
        ]);

        if ($validation->fails()) {
            throw new ValidationException($validation);
        }

        $make = trim((string) ($data['make'] ?? ''));
        $model = trim((string) $data['model']);

        $recent = Suggestion::where('user_id', $user->id)
            ->where('created_at', '>=', Carbon::now()->subHour())
            ->count();

        if ($recent >= 5) {
            throw new ValidationException(['model' => 'That is a lot of suggestions for one hour. Please try again later.']);
        }

        // One request per member per pen: repeated clicks should not inflate demand.
        $existing = Suggestion::where('user_id', $user->id)
            ->where('make', $make !== '' ? $make : null)
            ->where('model', $model)
            ->first();

        if (!$existing) {
            Suggestion::create([
                'user_id' => $user->id,
                'make'    => $make !== '' ? $make : null,
                'model'   => $model,
                'url'     => $data['url'] ?? null,
                'notes'   => $data['notes'] ?? null,
                'status'  => 'new',
                'ip'      => Request::ip(),
            ]);
        }

        return $this->successResponse();
    }

    protected function successResponse()
    {
        $this->page['sent'] = true;

        return ['#suggestPenWrap' => $this->renderPartial('@default')];
    }
}
