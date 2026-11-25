import InputError from '@/components/input-error';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';

export default function CheckboxField({
    id,
    label,
    checked,
    disabled = false,
    error,
    onChange,
}: {
    id: string;
    label: string;
    checked: boolean;
    disabled?: boolean;
    error?: string;
    onChange: (checked: boolean) => void;
}) {
    return (
        <div className="space-y-2">
            <div className="flex items-center gap-2">
                <Checkbox
                    id={id}
                    checked={checked}
                    disabled={disabled}
                    onCheckedChange={(value) => onChange(value === true)}
                />
                <Label htmlFor={id}>{label}</Label>
            </div>
            <InputError message={error} />
        </div>
    );
}
