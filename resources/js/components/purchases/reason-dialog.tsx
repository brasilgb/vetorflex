import { useForm } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import { useState } from 'react';
import FormField from '@/components/form-field';
import NativeTextarea from '@/components/native-textarea';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';

/** Ações que exigem motivo registrado: cancelamento, encerramento de saldo e estorno. */
export default function ReasonDialog({
    url,
    title,
    description,
    confirmLabel,
    ...props
}: {
    url: string;
    title: string;
    description: string;
    confirmLabel: string;
} & ComponentProps<typeof Button>) {
    const [isOpen, setIsOpen] = useState(false);
    const form = useForm({ reason: '' });

    return (
        <Dialog
            open={isOpen}
            onOpenChange={(open) => {
                setIsOpen(open);
                form.reset();
                form.clearErrors();
            }}
        >
            <DialogTrigger asChild>
                <Button type="button" variant="outline" {...props} />
            </DialogTrigger>
            <DialogContent>
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(url, {
                            preserveScroll: true,
                            onSuccess: () => setIsOpen(false),
                        });
                    }}
                >
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                    <FormField
                        id="action-reason"
                        label="Motivo"
                        error={
                            form.errors.reason ?? Object.values(form.errors)[0]
                        }
                    >
                        <NativeTextarea
                            id="action-reason"
                            required
                            maxLength={400}
                            value={form.data.reason}
                            onChange={(event) =>
                                form.setData('reason', event.target.value)
                            }
                        />
                    </FormField>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Voltar
                            </Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            disabled={form.processing}
                        >
                            {confirmLabel}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
