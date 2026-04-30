import { Component, OnInit, ChangeDetectorRef, NgZone, ElementRef, ViewChild } from '@angular/core';
import { CommonModule, NgOptimizedImage } from '@angular/common';
import { RouterModule, ActivatedRoute, Router } from '@angular/router';
import { FormsModule } from '@angular/forms';
import { MatSliderModule } from '@angular/material/slider';
import { MatSelectModule } from '@angular/material/select';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatIconModule } from '@angular/material/icon';
import { ProductoService } from '../../core/services/producto.service';
import { Producto } from '../../core/models/producto';
import { environment } from '../../../environments/environment';
import { CartService } from '../../core/services/cart.service';

@Component({
  selector: 'app-catalogo',
  standalone: true,
  imports: [
    CommonModule,
    NgOptimizedImage,
    RouterModule,
    FormsModule,
    MatSliderModule,
    MatSelectModule,
    MatFormFieldModule,
    MatInputModule,
    MatIconModule
  ],
  templateUrl: './catalogo.html',
  styleUrl: './catalogo.css'
})
export class Catalogo implements OnInit {

  @ViewChild('productsArea') productsArea!: ElementRef;

  productos: Producto[] = [];

  paginaActual = 1;
  totalPaginas = 1;
  totalProductos = 0;
  desde = 0;
  hasta = 0;
  isLoading = false;

  textoBusqueda = '';
  filtroCategoria = '';
  filtroUbicacion = '';
  filtroOrden = 'novedad';
  minPrice = 0;
  maxPrice = 100;

  productosFiltroMaestro: Producto[] = [];
  categoriasDisponibles: string[] = [];
  ubicacionesDisponibles: string[] = [];
  rangoMinPrecio = 0;
  rangoMaxPrecio = 1000;

  isBuyer = false;
  isFarmer = false;
  currentUser: any = null;
  filtrosAbiertos = false;

  toggleFiltros() { this.filtrosAbiertos = !this.filtrosAbiertos; }

  constructor(
    private productoService: ProductoService,
    private cartService: CartService,
    private cdr: ChangeDetectorRef,
    private ngZone: NgZone,
    private route: ActivatedRoute,
    private router: Router
  ) { }

  ngOnInit(): void {
    this.verificarUsuario();
    this.cargarOpcionesFiltros();
    this.route.queryParams.subscribe(params => {
      this.textoBusqueda = params['search'] ?? '';
      this.cargarProductos(1);
    });
  }

  verificarUsuario(): void {
    if (typeof window !== 'undefined' && window.localStorage) {
      const userStr = localStorage.getItem('user');
      if (userStr) {
        this.currentUser = JSON.parse(userStr);
        const role = this.currentUser.role?.toLowerCase();
        this.isFarmer = role === 'agricultor' || role === 'farmer' || this.currentUser.role_id === 2;
        this.isBuyer = role === 'comprador' || role === 'buyer' || this.currentUser.role_id === 3;
      } else {
        this.isBuyer = true;
      }
    }
  }

  getImagenUrl(prod: any): string {
    if (prod?.images?.length > 0) {
      const img = prod.images[0];
      if (img.image_url) {
        return img.image_url.startsWith('http')
          ? img.image_url
          : `${environment.storageUrl}/${img.image_url}`;
      }
      if (img.image_path) {
        return img.image_path.startsWith('http')
          ? img.image_path
          : `${environment.storageUrl}/${img.image_path}`;
      }
    }
    return '/assets/placeholder.png';
  }

  cargarOpcionesFiltros(): void {
    this.productoService.getCatalogo({ per_page: 1000 }).subscribe({
      next: (res: any) => {
        this.productosFiltroMaestro = res.data ?? [];
        this.recalcularFiltrosDinamicos(true);
      },
      error: (err) => console.error('Error cargando filtros dinámicos', err)
    });
  }

  recalcularFiltrosDinamicos(isInitial: boolean = false): void {
    if (!this.productosFiltroMaestro || this.productosFiltroMaestro.length === 0) return;

    const selectedCat = this.filtroCategoria;
    const selectedLoc = this.filtroUbicacion;

    const locMatch = (p: any, loc: string) => !loc || ((p.farmer?.city || p.farmer?.location) === loc);
    const catMatch = (p: any, cat: string) => !cat || (p.category?.name === cat);

    const cats = new Set<string>();
    this.productosFiltroMaestro.filter(p => locMatch(p, selectedLoc)).forEach((p: any) => {
      if (p.category?.name) cats.add(p.category.name);
    });
    this.categoriasDisponibles = Array.from(cats).sort();

    const locs = new Set<string>();
    this.productosFiltroMaestro.filter(p => catMatch(p, selectedCat)).forEach((p: any) => {
      const city = p.farmer?.city || p.farmer?.location;
      if (city) locs.add(city);
    });
    this.ubicacionesDisponibles = Array.from(locs).sort();

    const priceProducts = this.productosFiltroMaestro.filter(p =>
      catMatch(p, selectedCat) && locMatch(p, selectedLoc)
    );

    if (priceProducts.length > 0) {
      const prices = priceProducts.map(p => Number(p.price) || 0);
      let minGlobal = Math.floor(Math.min(...prices));
      let maxGlobal = Math.ceil(Math.max(...prices));
      if (minGlobal === maxGlobal) maxGlobal = minGlobal + 1;

      const wasFullyOpen = (this.minPrice <= this.rangoMinPrecio && this.maxPrice >= this.rangoMaxPrecio);

      this.rangoMinPrecio = minGlobal;
      this.rangoMaxPrecio = maxGlobal;

      if (isInitial || wasFullyOpen) {
        this.minPrice = minGlobal;
        this.maxPrice = maxGlobal;
      } else {
        if (this.minPrice < minGlobal) this.minPrice = minGlobal;
        if (this.minPrice > maxGlobal) this.minPrice = maxGlobal;
        if (this.maxPrice > maxGlobal) this.maxPrice = maxGlobal;
        if (this.maxPrice < minGlobal) this.maxPrice = minGlobal;
        if (this.minPrice > this.maxPrice) {
          this.minPrice = minGlobal;
          this.maxPrice = maxGlobal;
        }
      }
    } else {
      this.rangoMinPrecio = 0;
      this.rangoMaxPrecio = 100;
      this.minPrice = 0;
      this.maxPrice = 100;
    }
  }

  cargarProductos(page: number): void {
    if (this.productosFiltroMaestro.length > 0) {
      this.recalcularFiltrosDinamicos(false);
    }

    this.isLoading = true;

    const filtros = {
      page,
      per_page: 12,
      ...(this.textoBusqueda && { search: this.textoBusqueda }),
      ...(this.filtroCategoria && { category: this.filtroCategoria }),
      ...(this.filtroUbicacion && { location: this.filtroUbicacion }),
      ...(this.filtroOrden && { orden: this.filtroOrden }),
      min_price: this.minPrice,
      max_price: this.maxPrice,
    };

    this.productoService.getCatalogo(filtros).subscribe({
      next: (res: any) => {
        this.productos = res.data ?? [];
        this.paginaActual = res.current_page;
        this.totalPaginas = res.last_page;
        this.totalProductos = res.total;
        this.desde = res.from;
        this.hasta = res.to;
        this.isLoading = false;

        setTimeout(() => {
          this.cdr.detectChanges();
          window.scrollTo({ top: 0, behavior: 'smooth' });
          document.body.scrollTop = 0;
        });
      },
      error: () => {
        this.isLoading = false;
        setTimeout(() => this.cdr.detectChanges());
      }
    });
  }

  limpiarFiltros(): void {
    this.textoBusqueda = '';
    this.filtroCategoria = '';
    this.filtroUbicacion = '';
    this.filtroOrden = 'novedad';
    this.minPrice = this.rangoMinPrecio;
    this.maxPrice = this.rangoMaxPrecio;
    this.cargarProductos(1);
  }

  cambiarPagina(nuevaPagina: number): void {
    if (nuevaPagina >= 1 && nuevaPagina <= this.totalPaginas) {
      this.cargarProductos(nuevaPagina);
    }
  }

  get numeracionPaginas(): number[] {
    const maxVisible = 5;
    if (this.totalPaginas <= maxVisible) {
      return Array.from({ length: this.totalPaginas }, (_, i) => i + 1);
    }
    let start = this.paginaActual - 2;
    let end = this.paginaActual + 2;
    if (start < 1) { start = 1; end = maxVisible; }
    if (end > this.totalPaginas) { end = this.totalPaginas; start = end - maxVisible + 1; }
    const pages: number[] = [];
    for (let i = start; i <= end; i++) pages.push(i);
    return pages;
  }

  formatLabel(value: number): string {
    return value >= 1000 ? `${Math.round(value / 1000)}k` : `${value}€`;
  }

  toggleFavorite(prod: Producto): void {
    if (!localStorage.getItem('token')) {
      this.router.navigate(['/login']);
      return;
    }
    prod.is_favorite = !prod.is_favorite;
    this.productoService.toggleFavorite(prod.id).subscribe({
      error: () => { prod.is_favorite = !prod.is_favorite; }
    });
  }

  agregarAlCarrito(prod: Producto): void {
    this.cartService.addToCart({
      id: prod.id,
      name: prod.name,
      farmer: prod.farmer?.user?.name || prod.farmer?.full_name || prod.farmer?.name || 'Agricultor local',
      farmerId: this.getFarmerUserId(prod) ?? 0,
      price: Number(prod.price),
      unit: prod.unit,
      quantity: 1,
      image: this.getImagenUrl(prod)
    });
  }

  private getFarmerUserId(prod: Producto): number | null {
    const rawFarmerUserId = (prod as any)?.farmer?.user_id;
    const rawFarmerId = (prod as any)?.farmer?.id;
    const id = Number(rawFarmerUserId ?? rawFarmerId);
    return Number.isFinite(id) && id > 0 ? id : null;
  }
}