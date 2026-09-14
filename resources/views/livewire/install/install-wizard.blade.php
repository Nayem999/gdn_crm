<div>
    {{-- The step rail. `step` is locked server-side, so this reports progress
         rather than offering navigation: a step behind you is reached through
         the Back button, never by clicking the rail. --}}
    <ol class="mb-8 flex items-center gap-2" aria-label="Installation steps">
        @foreach (['Company', 'Administrator', 'Email'] as $index => $label)
            @php($number = $index + 1)
            <li class="flex flex-1 items-center gap-2">
                <span @class([
                    'flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                    'bg-accent text-accent-foreground' => $number <= $step,
                    'border border-border text-muted-foreground' => $number > $step,
                ]) @if ($number === $step) aria-current="step" @endif>
                    @if ($number < $step)
                        <x-icon name="lucide-check" class="h-4 w-4" />
                    @else
                        {{ $number }}
                    @endif
                </span>
                <span @class([
                    'text-sm',
                    'font-medium text-foreground' => $number === $step,
                    'text-muted-foreground' => $number !== $step,
                ])>{{ $label }}</span>
                @unless ($loop->last)
                    <span class="h-px flex-1 bg-border" aria-hidden="true"></span>
                @endunless
            </li>
        @endforeach
    </ol>

    @error('step')
        <x-alert variant="error" class="mb-6">{{ $message }}</x-alert>
    @enderror

    @if ($step === 1)
        <form wire:submit="next" class="space-y-5">
            <div>
                <h1 class="text-xl font-semibold text-foreground">Tell us about your company</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    This is the organisation the CRM belongs to. Everything here can be changed later
                    under Settings &rarr; Company.
                </p>
            </div>

            <div>
                <x-form.label for="companyName" required>Company name</x-form.label>
                <x-form.input id="companyName" wire:model="companyName" :invalid="$errors->has('companyName')" autofocus />
                <x-form.error for="companyName" />
            </div>

            <x-select
                name="timezone"
                label="Timezone"
                :options="$this->timezoneOptions()"
                :selected="$timezone"
                :error="$errors->first('timezone')"
                hint="The clock your office reads. Times are stored in UTC and displayed in this zone."
                required
                wire:model="timezone"
            />

            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <x-select
                    name="currency"
                    label="Currency"
                    :options="$this->currencyOptions()"
                    :selected="$currency"
                    :error="$errors->first('currency')"
                    required
                    wire:model="currency"
                />

                <x-select
                    name="fiscalYearStartMonth"
                    label="Fiscal year starts"
                    :options="$this->monthOptions()"
                    :selected="$fiscalYearStartMonth"
                    :error="$errors->first('fiscalYearStartMonth')"
                    required
                    wire:model="fiscalYearStartMonth"
                />
            </div>

            <div class="flex justify-end pt-2">
                <x-button type="submit">Continue</x-button>
            </div>
        </form>
    @elseif ($step === 2)
        <form wire:submit="next" class="space-y-5">
            <div>
                <h1 class="text-xl font-semibold text-foreground">Create the administrator</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    The first account owns the installation: it holds every permission, and it is the
                    one that invites everybody else.
                </p>
            </div>

            <div>
                <x-form.label for="adminName" required>Full name</x-form.label>
                <x-form.input id="adminName" wire:model="adminName" :invalid="$errors->has('adminName')" autocomplete="name" autofocus />
                <x-form.error for="adminName" />
            </div>

            <div>
                <x-form.label for="adminEmail" required>Email address</x-form.label>
                <x-form.input id="adminEmail" type="email" wire:model="adminEmail" :invalid="$errors->has('adminEmail')" autocomplete="username" />
                <x-form.error for="adminEmail" />
            </div>

            <div>
                <x-form.label for="adminPassword" required>Password</x-form.label>
                <x-form.password id="adminPassword" wire:model="adminPassword" :invalid="$errors->has('adminPassword')" autocomplete="new-password" />
                <x-form.error for="adminPassword" />
            </div>

            <div>
                <x-form.label for="adminPasswordConfirmation" required>Confirm password</x-form.label>
                <x-form.password id="adminPasswordConfirmation" wire:model="adminPasswordConfirmation" autocomplete="new-password" />
            </div>

            <div class="flex items-center justify-between pt-2">
                <x-button type="button" variant="secondary" wire:click="back">Back</x-button>
                <x-button type="submit">Continue</x-button>
            </div>
        </form>
    @else
        <form wire:submit="install" class="space-y-5">
            <div>
                <h1 class="text-xl font-semibold text-foreground">How should email be sent?</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Notifications, invitations and password resets go out through this provider. Leaving
                    it on the log driver finishes the setup now; email can be configured later under
                    Settings &rarr; Email.
                </p>
            </div>

            <x-select
                name="mailProvider"
                label="Send email through"
                :options="$this->providerOptions()"
                :selected="$mailProvider"
                :error="$errors->first('mailProvider')"
                :hint="$this->providerDescription()"
                required
                wire:model.live="mailProvider"
            />

            @foreach ($this->providerFields() as $field)
                <div>
                    <x-form.label :for="'mail-' . $field->key">{{ $field->label }}</x-form.label>

                    @if ($field->secret)
                        {{-- A plain password box, not <x-form.secret>: there is nothing
                             stored yet to keep, so its "replace" affordance has nothing
                             to replace. --}}
                        <x-form.password
                            :id="'mail-' . $field->key"
                            wire:model="mailCredentials.{{ $field->key }}"
                            :invalid="$errors->has('mailCredentials.' . $field->key)"
                            autocomplete="off"
                        />
                    @elseif ($field->options !== [])
                        <x-select
                            :name="'mail-' . $field->key"
                            :options="$field->options"
                            :selected="$mailCredentials[$field->key] ?? $field->default"
                            :error="$errors->first('mailCredentials.' . $field->key)"
                            wire:model="mailCredentials.{{ $field->key }}"
                        />
                    @else
                        <x-form.input
                            :id="'mail-' . $field->key"
                            wire:model="mailCredentials.{{ $field->key }}"
                            :invalid="$errors->has('mailCredentials.' . $field->key)"
                        />
                    @endif

                    @unless ($field->options !== [] && ! $field->secret)
                        <x-form.error :for="'mailCredentials.' . $field->key" />

                        @if ($field->help)
                            <p class="mt-1.5 text-xs text-muted-foreground">{{ $field->help }}</p>
                        @endif
                    @endunless
                </div>
            @endforeach

            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div>
                    <x-form.label for="fromAddress">From address</x-form.label>
                    <x-form.input id="fromAddress" type="email" wire:model="fromAddress" :invalid="$errors->has('fromAddress')" />
                    <x-form.error for="fromAddress" />
                </div>

                <div>
                    <x-form.label for="fromName">From name</x-form.label>
                    <x-form.input id="fromName" wire:model="fromName" :invalid="$errors->has('fromName')" />
                    <x-form.error for="fromName" />
                </div>
            </div>

            <div class="flex items-center justify-between pt-2">
                <x-button type="button" variant="secondary" wire:click="back">Back</x-button>
                <x-button type="submit" wire:loading.attr="disabled" wire:target="install">
                    <span wire:loading wire:target="install" class="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent"></span>
                    Finish setup
                </x-button>
            </div>
        </form>
    @endif
</div>
