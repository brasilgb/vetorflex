import { useForm, useHttp } from '@inertiajs/react';
import { useState } from 'react';
import CheckboxField from '@/components/checkbox-field';
import FormField from '@/components/form-field';
import InputError from '@/components/input-error';
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
import { useTenant } from '@/hooks/use-tenant';
import { parseDecimal } from '@/lib/decimal';
import { preview, store } from '@/routes/products/variants';

type AttributeRow = { name: string; values: string };

type Combination = { label: string; exists: boolean };

const SUGGESTIONS = [
    'Cor',
    'Tamanho',
    'Modelo',
    'Material',
    'Voltagem',
    'Capacidade',
];

const MAXIMUM_ATTRIBUTES = 5;

function toAttributes(rows: AttributeRow[]) {
    return rows
        .map(({ name, values }) => ({
            name: name.trim(),
            values: values
                .split(',')
                .map((value) => value.trim())
                .filter((value) => value !== ''),
        }))
        .filter(({ name, values }) => name !== '' || values.length > 0);
}

function firstError(errors: object): string | undefined {
    return (Object.values(errors) as string[])[0];
}

function GeneratorForm({
    productId,
    onSaved,
}: {
    productId: number;
    onSaved: () => void;
}) {
    const { can } = useTenant();
    const [rows, setRows] = useState<AttributeRow[]>([
        { name: '', values: '' },
    ]);
    const [combinations, setCombinations] = useState<Combination[] | null>(
        null,
    );
    const [labels, setLabels] = useState<string[]>([]);
    const [failure, setFailure] = useState<string>();
    const http = useHttp<{ attributes: object[] }, Combination[]>({
        attributes: [],
    });
    const form = useForm({
        generate_sku: true,
        cost_price: '',
        sale_price: '',
    });

    function changeRows(next: AttributeRow[]) {
        setRows(next);
        // A confirmação vale somente para a prévia exibida.
        setCombinations(null);
    }

    function loadPreview() {
        setFailure(undefined);
        http.transform(() => ({ attributes: toAttributes(rows) }));
        http.post(preview.url(productId), {
            onSuccess: (found) => {
                setCombinations(found);
                setLabels(
                    found
                        .filter(({ exists }) => !exists)
                        .map(({ label }) => label),
                );
            },
        }).catch(() =>
            setFailure('Não foi possível gerar a prévia. Tente novamente.'),
        );
    }

    function confirm() {
        form.transform((current) => ({
            ...current,
            cost_price: parseDecimal(current.cost_price),
            sale_price: parseDecimal(current.sale_price),
            attributes: toAttributes(rows),
            labels,
        }));
        form.submit(store(productId), {
            preserveScroll: true,
            onSuccess: onSaved,
        });
    }

    return (
        <div className="space-y-4">
            <datalist id="attribute-suggestions">
                {SUGGESTIONS.map((name) => (
                    <option key={name} value={name} />
                ))}
            </datalist>
            {rows.map((row, position) => (
                <div
                    key={position}
                    className="grid gap-2 sm:grid-cols-[10rem_1fr_auto]"
                >
                    <Input
                        aria-label={`Atributo ${position + 1}`}
                        placeholder="Atributo (ex.: Cor)"
                        list="attribute-suggestions"
                        maxLength={60}
                        value={row.name}
                        onChange={(event) =>
                            changeRows(
                                rows.map((current, index) =>
                                    index === position
                                        ? {
                                              ...current,
                                              name: event.target.value,
                                          }
                                        : current,
                                ),
                            )
                        }
                    />
                    <Input
                        aria-label={`Valores do atributo ${position + 1}`}
                        placeholder="Valores separados por vírgula"
                        value={row.values}
                        onChange={(event) =>
                            changeRows(
                                rows.map((current, index) =>
                                    index === position
                                        ? {
                                              ...current,
                                              values: event.target.value,
                                          }
                                        : current,
                                ),
                            )
                        }
                    />
                    <Button
                        type="button"
                        variant="ghost"
                        disabled={rows.length === 1}
                        onClick={() =>
                            changeRows(
                                rows.filter((_, index) => index !== position),
                            )
                        }
                    >
                        Remover
                    </Button>
                </div>
            ))}
            <div className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    variant="outline"
                    disabled={rows.length >= MAXIMUM_ATTRIBUTES}
                    onClick={() =>
                        changeRows([...rows, { name: '', values: '' }])
                    }
                >
                    Adicionar atributo
                </Button>
                <Button
                    type="button"
                    disabled={http.processing}
                    onClick={loadPreview}
                >
                    Gerar prévia
                </Button>
            </div>
            <InputError message={failure ?? firstError(http.errors)} />

            {combinations !== null && (
                <>
                    <ul className="grid max-h-56 gap-2 overflow-y-auto rounded-md border p-3 sm:grid-cols-2">
                        {combinations.map(({ label, exists }) => (
                            <li key={label}>
                                <CheckboxField
                                    id={`combination-${label}`}
                                    label={
                                        exists ? `${label} (já existe)` : label
                                    }
                                    disabled={exists}
                                    checked={labels.includes(label)}
                                    onChange={(checked) =>
                                        setLabels(
                                            checked
                                                ? [...labels, label]
                                                : labels.filter(
                                                      (current) =>
                                                          current !== label,
                                                  ),
                                        )
                                    }
                                />
                            </li>
                        ))}
                    </ul>
                    <div className="grid gap-4 sm:grid-cols-2">
                        {can('products.view_cost') &&
                            can('products.manage_cost') && (
                                <FormField
                                    id="generator-cost"
                                    label="Custo das novas variações (R$)"
                                >
                                    <Input
                                        id="generator-cost"
                                        inputMode="decimal"
                                        value={form.data.cost_price}
                                        onChange={(event) =>
                                            form.setData(
                                                'cost_price',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </FormField>
                            )}
                        {can('products.manage_prices') && (
                            <FormField
                                id="generator-sale"
                                label="Venda das novas variações (R$)"
                            >
                                <Input
                                    id="generator-sale"
                                    inputMode="decimal"
                                    value={form.data.sale_price}
                                    onChange={(event) =>
                                        form.setData(
                                            'sale_price',
                                            event.target.value,
                                        )
                                    }
                                />
                            </FormField>
                        )}
                    </div>
                    <CheckboxField
                        id="generator-sku"
                        label="Gerar SKU automaticamente"
                        checked={form.data.generate_sku}
                        onChange={(checked) =>
                            form.setData('generate_sku', checked)
                        }
                    />
                    <InputError message={firstError(form.errors)} />
                    <DialogFooter>
                        <Button
                            type="button"
                            disabled={form.processing || labels.length === 0}
                            onClick={confirm}
                        >
                            Criar {labels.length} variação(ões)
                        </Button>
                    </DialogFooter>
                </>
            )}
        </div>
    );
}

export default function VariantGenerator({ productId }: { productId: number }) {
    const [isOpen, setIsOpen] = useState(false);

    return (
        <Dialog open={isOpen} onOpenChange={setIsOpen}>
            <DialogTrigger asChild>
                <Button type="button">Gerar variações</Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogTitle>Gerar variações</DialogTitle>
                <DialogDescription>
                    Informe os atributos, confira a prévia e confirme as
                    combinações desejadas.
                </DialogDescription>
                <GeneratorForm
                    productId={productId}
                    onSaved={() => setIsOpen(false)}
                />
            </DialogContent>
        </Dialog>
    );
}
