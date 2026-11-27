import { useHttp } from '@inertiajs/react';
import { useState } from 'react';
import FormField from '@/components/form-field';
import NativeSelect from '@/components/native-select';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { store } from '@/routes/purchases/items';
import type { PurchasableItem } from '@/types';

/** Cadastro simplificado de mercadoria ou serviço no catálogo compartilhado, sem sair do pedido de compra. */
export default function QuickPurchaseItemDialog({
    onCreated,
}: {
    onCreated: (item: PurchasableItem) => void;
}) {
    const [isOpen, setIsOpen] = useState(false);
    const http = useHttp<
        { name: string; type: string; sku: string },
        PurchasableItem
    >({ name: '', type: 'simple', sku: '' });

    return (
        <Dialog
            open={isOpen}
            onOpenChange={(open) => {
                setIsOpen(open);
                http.reset();
                http.clearErrors();
            }}
        >
            <DialogTrigger asChild>
                <Button type="button" variant="outline" size="sm">
                    Cadastrar item
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        http.transform((data) => ({
                            ...data,
                            sku: data.sku.trim() === '' ? null : data.sku,
                        }));
                        http.post(store.url(), {
                            onSuccess: (item) => {
                                onCreated(item);
                                setIsOpen(false);
                            },
                        }).catch(() => undefined);
                    }}
                >
                    <DialogTitle>Cadastrar item</DialogTitle>
                    <DialogDescription>
                        O item entra no catálogo da empresa e pode ser
                        completado depois no Estoque ou em Produtos.
                    </DialogDescription>
                    <FormField
                        id="purchase-item-name"
                        label="Nome"
                        error={http.errors.name}
                    >
                        <Input
                            id="purchase-item-name"
                            required
                            maxLength={255}
                            value={http.data.name}
                            onChange={(event) =>
                                http.setData('name', event.target.value)
                            }
                        />
                    </FormField>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <FormField
                            id="purchase-item-type"
                            label="Tipo"
                            error={http.errors.type}
                        >
                            <NativeSelect
                                id="purchase-item-type"
                                value={http.data.type}
                                onChange={(event) =>
                                    http.setData('type', event.target.value)
                                }
                            >
                                <option value="simple">Mercadoria</option>
                                <option value="service">Serviço</option>
                            </NativeSelect>
                        </FormField>
                        <FormField
                            id="purchase-item-sku"
                            label="SKU (opcional)"
                            error={http.errors.sku}
                        >
                            <Input
                                id="purchase-item-sku"
                                maxLength={60}
                                value={http.data.sku}
                                onChange={(event) =>
                                    http.setData('sku', event.target.value)
                                }
                            />
                        </FormField>
                    </div>
                    <DialogFooter>
                        <Button disabled={http.processing}>
                            Cadastrar e incluir
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
