import { router } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import { useState } from 'react';
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

export default function ConfirmButton({
    route,
    title,
    description,
    confirmLabel,
    ...props
}: {
    route: { url: string; method: 'patch' | 'delete' | 'post' };
    title: string;
    description: string;
    confirmLabel: string;
} & ComponentProps<typeof Button>) {
    const [isOpen, setIsOpen] = useState(false);
    const [isProcessing, setIsProcessing] = useState(false);

    return (
        <Dialog open={isOpen} onOpenChange={setIsOpen}>
            <DialogTrigger asChild>
                <Button type="button" variant="outline" {...props} />
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>{title}</DialogTitle>
                <DialogDescription>{description}</DialogDescription>
                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button type="button" variant="secondary">
                            Cancelar
                        </Button>
                    </DialogClose>
                    <Button
                        type="button"
                        variant="destructive"
                        disabled={isProcessing}
                        onClick={() =>
                            router.visit(route.url, {
                                method: route.method,
                                preserveScroll: true,
                                onStart: () => setIsProcessing(true),
                                onSuccess: () => setIsOpen(false),
                                onFinish: () => setIsProcessing(false),
                            })
                        }
                    >
                        {confirmLabel}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
