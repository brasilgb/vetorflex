import FormField from '@/components/form-field';
import { Input } from '@/components/ui/input';

export type Credentials = { email: string; password: string };

/** Credenciais de um supervisor com `pos.authorize`, pedidas quando o servidor exige autorização. */
export default function AuthorizationFields({
    id,
    value,
    onChange,
    error,
}: {
    id: string;
    value: Credentials;
    onChange: (value: Credentials) => void;
    error?: string;
}) {
    return (
        <fieldset className="space-y-3 rounded-md border border-amber-500/50 bg-amber-500/5 p-3">
            <legend className="px-1 text-sm font-medium">
                Autorização do supervisor
            </legend>
            {error && <p className="text-sm text-destructive">{error}</p>}
            <div className="grid gap-3 sm:grid-cols-2">
                <FormField id={`${id}-email`} label="E-mail do supervisor">
                    <Input
                        id={`${id}-email`}
                        type="email"
                        autoComplete="off"
                        value={value.email}
                        onChange={(event) =>
                            onChange({ ...value, email: event.target.value })
                        }
                    />
                </FormField>
                <FormField id={`${id}-password`} label="Senha do supervisor">
                    <Input
                        id={`${id}-password`}
                        type="password"
                        autoComplete="new-password"
                        value={value.password}
                        onChange={(event) =>
                            onChange({ ...value, password: event.target.value })
                        }
                    />
                </FormField>
            </div>
        </fieldset>
    );
}

export const emptyCredentials: Credentials = { email: '', password: '' };

/** Envia as credenciais somente quando preenchidas. */
export function credentialsPayload(value: Credentials): Credentials | null {
    return value.email.trim() !== '' && value.password !== '' ? value : null;
}
