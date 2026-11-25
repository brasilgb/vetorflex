import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import FormField from '@/components/form-field';
import InputError from '@/components/input-error';
import NativeSelect from '@/components/native-select';
import NativeTextarea from '@/components/native-textarea';
import SellableUnitFields, {
    normalizeSellableUnit,
    sellableUnitData,
} from '@/components/products/sellable-unit-fields';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTenant } from '@/hooks/use-tenant';
import { parseDecimal } from '@/lib/decimal';
import { index, show, store, update } from '@/routes/products';
import type { Product, ProductFormOptions, ProductVariant } from '@/types';

const MERCHANDISE_ORIGINS = [
    '0 - Nacional',
    '1 - Estrangeira, importação direta',
    '2 - Estrangeira, adquirida no mercado interno',
    '3 - Nacional, conteúdo importado acima de 40% até 70%',
    '4 - Nacional, processos produtivos básicos',
    '5 - Nacional, conteúdo importado até 40%',
    '6 - Estrangeira, importação direta, sem similar nacional',
    '7 - Estrangeira, mercado interno, sem similar nacional',
    '8 - Nacional, conteúdo importado acima de 70%',
];

export default function ProductForm({
    product = null,
    variant = null,
    options,
}: {
    product?: Product | null;
    variant?: ProductVariant | null;
    options: ProductFormOptions;
}) {
    const { can } = useTenant();
    const form = useForm({
        type: product?.type ?? 'simple',
        name: product?.name ?? '',
        short_description: product?.short_description ?? '',
        description: product?.description ?? '',
        internal_code: product?.internal_code ?? '',
        product_category_id: String(product?.product_category_id ?? ''),
        product_brand_id: String(product?.product_brand_id ?? ''),
        unit_of_measure_id: String(product?.unit_of_measure_id ?? ''),
        manufacturer_reference: product?.manufacturer_reference ?? '',
        tracks_inventory: product?.tracks_inventory ?? true,
        ncm: product?.ncm ?? '',
        cest: product?.cest ?? '',
        merchandise_origin: product?.merchandise_origin ?? '',
        notes: product?.notes ?? '',
        initial_stock: '',
        ...sellableUnitData(variant),
    });
    const { data, setData } = form;
    // O serviço de estoque devolve erros do estoque inicial no campo "quantity".
    const errors: typeof form.errors & { quantity?: string } = form.errors;
    const isPhysical = data.type !== 'service';
    const isVariable = data.type === 'variable';
    const controlsStock = isPhysical && data.tracks_inventory;

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((current) => ({
            ...normalizeSellableUnit(current),
            initial_stock: parseDecimal(current.initial_stock),
        }));
        form.submit(product === null ? store() : update(product.id));
    }

    return (
        <form className="space-y-6" onSubmit={submit}>
            <Card>
                <CardHeader>
                    <CardTitle>Identificação</CardTitle>
                </CardHeader>
                <CardContent className="grid gap-4 md:grid-cols-3">
                    <FormField id="type" label="Tipo" error={errors.type}>
                        <NativeSelect
                            id="type"
                            value={data.type}
                            disabled={product !== null}
                            onChange={(event) =>
                                setData(
                                    'type',
                                    event.target.value as Product['type'],
                                )
                            }
                        >
                            {options.types.map((type) => (
                                <option key={type.value} value={type.value}>
                                    {type.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </FormField>
                    <FormField
                        id="name"
                        label="Nome"
                        error={errors.name}
                        className="md:col-span-2"
                    >
                        <Input
                            id="name"
                            required
                            maxLength={255}
                            value={data.name}
                            onChange={(event) =>
                                setData('name', event.target.value)
                            }
                        />
                    </FormField>
                    <FormField
                        id="product_category_id"
                        label="Categoria"
                        error={errors.product_category_id}
                    >
                        <NativeSelect
                            id="product_category_id"
                            value={data.product_category_id}
                            onChange={(event) =>
                                setData(
                                    'product_category_id',
                                    event.target.value,
                                )
                            }
                        >
                            <option value="">Sem categoria</option>
                            {options.categories.map((category) => (
                                <option key={category.id} value={category.id}>
                                    {category.name}
                                </option>
                            ))}
                        </NativeSelect>
                    </FormField>
                    <FormField
                        id="product_brand_id"
                        label="Marca"
                        error={errors.product_brand_id}
                    >
                        <NativeSelect
                            id="product_brand_id"
                            value={data.product_brand_id}
                            onChange={(event) =>
                                setData('product_brand_id', event.target.value)
                            }
                        >
                            <option value="">Sem marca</option>
                            {options.brands.map((brand) => (
                                <option key={brand.id} value={brand.id}>
                                    {brand.name}
                                </option>
                            ))}
                        </NativeSelect>
                    </FormField>
                    <FormField
                        id="unit_of_measure_id"
                        label="Unidade de medida"
                        error={errors.unit_of_measure_id}
                    >
                        <NativeSelect
                            id="unit_of_measure_id"
                            value={data.unit_of_measure_id}
                            onChange={(event) =>
                                setData(
                                    'unit_of_measure_id',
                                    event.target.value,
                                )
                            }
                        >
                            {product === null && (
                                <option value="">Padrão do tipo</option>
                            )}
                            {options.units.map((unit) => (
                                <option key={unit.id} value={unit.id}>
                                    {unit.name} ({unit.code})
                                </option>
                            ))}
                        </NativeSelect>
                    </FormField>
                    <FormField
                        id="internal_code"
                        label="Código interno"
                        error={errors.internal_code}
                    >
                        <Input
                            id="internal_code"
                            value={data.internal_code}
                            onChange={(event) =>
                                setData('internal_code', event.target.value)
                            }
                        />
                    </FormField>
                    <FormField
                        id="manufacturer_reference"
                        label="Referência do fabricante"
                        error={errors.manufacturer_reference}
                    >
                        <Input
                            id="manufacturer_reference"
                            value={data.manufacturer_reference}
                            onChange={(event) =>
                                setData(
                                    'manufacturer_reference',
                                    event.target.value,
                                )
                            }
                        />
                    </FormField>
                    <FormField
                        id="short_description"
                        label="Descrição curta"
                        error={errors.short_description}
                        className="md:col-span-3"
                    >
                        <Input
                            id="short_description"
                            maxLength={500}
                            value={data.short_description}
                            onChange={(event) =>
                                setData('short_description', event.target.value)
                            }
                        />
                    </FormField>
                    <FormField
                        id="description"
                        label="Descrição completa"
                        error={errors.description}
                        className="md:col-span-3"
                    >
                        <NativeTextarea
                            id="description"
                            value={data.description}
                            onChange={(event) =>
                                setData('description', event.target.value)
                            }
                        />
                    </FormField>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Preços, códigos e estoque</CardTitle>
                </CardHeader>
                <CardContent className="space-y-6">
                    {isPhysical && (
                        <div className="space-y-2">
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="tracks_inventory"
                                    checked={data.tracks_inventory}
                                    onCheckedChange={(checked) =>
                                        setData(
                                            'tracks_inventory',
                                            checked === true,
                                        )
                                    }
                                />
                                <Label htmlFor="tracks_inventory">
                                    Controlar estoque
                                </Label>
                            </div>
                            <InputError message={errors.tracks_inventory} />
                        </div>
                    )}
                    {isVariable ? (
                        <p className="text-sm text-muted-foreground">
                            Preços, códigos e estoque pertencem a cada variação
                            e são definidos na ficha do produto.
                        </p>
                    ) : (
                        <SellableUnitFields
                            data={data}
                            errors={errors}
                            barcodeTypes={options.barcodeTypes}
                            isPhysical={isPhysical}
                            controlsStock={controlsStock}
                            onChange={(changes) =>
                                setData((current) => ({
                                    ...current,
                                    ...changes,
                                }))
                            }
                        />
                    )}
                    {product === null &&
                        data.type === 'simple' &&
                        controlsStock &&
                        can('products.adjust_inventory') && (
                            <FormField
                                id="initial_stock"
                                label="Estoque inicial"
                                hint="Registrado como movimentação. Depois do cadastro, o saldo só muda por movimentações."
                                error={errors.initial_stock ?? errors.quantity}
                                className="max-w-xs"
                            >
                                <Input
                                    id="initial_stock"
                                    inputMode="decimal"
                                    value={data.initial_stock}
                                    onChange={(event) =>
                                        setData(
                                            'initial_stock',
                                            event.target.value,
                                        )
                                    }
                                />
                            </FormField>
                        )}
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Informações fiscais e observações</CardTitle>
                </CardHeader>
                <CardContent className="grid gap-4 md:grid-cols-3">
                    <p className="text-sm text-muted-foreground md:col-span-3">
                        Campos opcionais e apenas informativos: o preenchimento
                        não garante conformidade fiscal.
                    </p>
                    <FormField id="ncm" label="NCM" error={errors.ncm}>
                        <Input
                            id="ncm"
                            inputMode="numeric"
                            value={data.ncm}
                            onChange={(event) =>
                                setData('ncm', event.target.value)
                            }
                        />
                    </FormField>
                    <FormField id="cest" label="CEST" error={errors.cest}>
                        <Input
                            id="cest"
                            inputMode="numeric"
                            value={data.cest}
                            onChange={(event) =>
                                setData('cest', event.target.value)
                            }
                        />
                    </FormField>
                    <FormField
                        id="merchandise_origin"
                        label="Origem da mercadoria"
                        error={errors.merchandise_origin}
                    >
                        <NativeSelect
                            id="merchandise_origin"
                            value={data.merchandise_origin}
                            onChange={(event) =>
                                setData(
                                    'merchandise_origin',
                                    event.target.value,
                                )
                            }
                        >
                            <option value="">Não informada</option>
                            {MERCHANDISE_ORIGINS.map((label, origin) => (
                                <option key={label} value={origin}>
                                    {label}
                                </option>
                            ))}
                        </NativeSelect>
                    </FormField>
                    <FormField
                        id="notes"
                        label="Observações"
                        error={errors.notes}
                        className="md:col-span-3"
                    >
                        <NativeTextarea
                            id="notes"
                            value={data.notes}
                            onChange={(event) =>
                                setData('notes', event.target.value)
                            }
                        />
                    </FormField>
                </CardContent>
            </Card>

            <div className="flex gap-2">
                <Button disabled={form.processing}>
                    {product === null ? 'Cadastrar' : 'Salvar alterações'}
                </Button>
                <Button variant="outline" asChild>
                    <Link href={product === null ? index() : show(product.id)}>
                        Cancelar
                    </Link>
                </Button>
            </div>
        </form>
    );
}
