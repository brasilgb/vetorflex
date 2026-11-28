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
import { parseDecimal } from '@/lib/decimal';
import { store } from '@/routes/sales/items';
import type { SellableItem } from '@/types';

/** Cadastro simplificado de produto ou serviço no catálogo compartilhado, sem sair da venda. */
export default function QuickItemDialog({
    onCreated,
}: {
    onCreated: (item: SellableItem) => void;
}) {
    const [isOpen, setIsOpen] = useState(false);
    const http = useHttp<
        { name: string; type: string; sale_price: string; sku: string },
        SellableItem
    >({
        name: '',
        type: 'simple',
        sale_price: '',
        sku: '',
    });

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
                            sale_price: parseDecimal(data.sale_price),
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
                        completado depois no módulo Produtos.
                    </DialogDescription>
                    <FormField
                        id="quick-item-name"
                        label="Nome"
                        error={http.errors.name}
                    >
                        <Input
                            id="quick-item-name"
                            required
                            maxLength={255}
                            value={http.data.name}
                            onChange={(event) =>
                                http.setData('name', event.target.value)
                            }
                        />
                    </FormField>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <FormField
                            id="quick-item-type"
                            label="Tipo"
                            error={http.errors.type}
                        >
                            <NativeSelect
                                id="quick-item-type"
                                value={http.data.type}
                                onChange={(event) =>
                                    http.setData('type', event.target.value)
                                }
                            >
                                <option value="simple">Produto</option>
                                <option value="service">Serviço</option>
                            </NativeSelect>
                        </FormField>
                        <FormField
                            id="quick-item-price"
                            label="Preço de venda"
                            error={http.errors.sale_price}
                        >
                            <Input
                                id="quick-item-price"
                                required
                                inputMode="decimal"
                                value={http.data.sale_price}
                                onChange={(event) =>
                                    http.setData(
                                        'sale_price',
                                        event.target.value,
                                    )
                                }
                            />
                        </FormField>
                        <FormField
                            id="quick-item-sku"
                            label="SKU (opcional)"
                            error={http.errors.sku}
                        >
                            <Input
                                id="quick-item-sku"
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
