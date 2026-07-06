import React, { useState, useEffect } from 'react';
import { useCustomerAuthStore } from '../../stores/customerAuthStore';
import { 
  X, 
  User, 
  Heart, 
  ShoppingBag, 
  LogOut, 
  Mail, 
  Lock, 
  Loader2, 
  UserPlus
} from 'lucide-react';
import { toast } from 'react-hot-toast';
import api from '../../api/axios';
import type { Product, Order } from '../../types';

interface CustomerAccountModalProps {
  isOpen: boolean;
  onClose: () => void;
  tenantSlug: string;
}

export default function CustomerAccountModal({ isOpen, onClose, tenantSlug }: CustomerAccountModalProps) {
  const { user, token, isAuthenticated, setCustomerAuth, clearCustomerAuth, setFavoriteIds, toggleFavoriteId } = useCustomerAuthStore();
  const [activeTab, setActiveTab] = useState<'login' | 'register' | 'favorites' | 'orders'>('login');
  
  // Form states
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [name, setName] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  
  // Data states
  const [loading, setLoading] = useState(false);
  const [favorites, setFavorites] = useState<Product[]>([]);
  const [orders, setOrders] = useState<Order[]>([]);

  // Automatically switch tab depending on auth status
  useEffect(() => {
    if (isAuthenticated) {
      setActiveTab('favorites');
      fetchFavorites();
      fetchOrders();
    } else {
      setActiveTab('login');
    }
  }, [isAuthenticated, isOpen]);

  const fetchFavorites = async () => {
    if (!token) return;
    try {
      const res = await api.get(`/public/${tenantSlug}/favorites`);
      setFavorites(res.data);
      setFavoriteIds(res.data.map((p: any) => p.id));
    } catch {
      toast.error('Error al cargar favoritos');
    }
  };

  const fetchOrders = async () => {
    if (!token) return;
    try {
      const res = await api.get(`/public/${tenantSlug}/my-orders`);
      setOrders(res.data);
    } catch {
      toast.error('Error al cargar el historial de pedidos');
    }
  };

  const handleLogin = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!email || !password) {
      toast.error('Por favor, completa todos los campos.');
      return;
    }
    setLoading(true);
    try {
      const res = await api.post(`/public/${tenantSlug}/auth/login`, { email, password });
      setCustomerAuth(res.data.token, res.data.user);
      toast.success(`¡Bienvenido de vuelta, ${res.data.user.name}!`);
      fetchFavorites();
      fetchOrders();
    } catch (err: any) {
      const errMsg = err.response?.data?.message || err.response?.data?.errors?.email?.[0] || 'Error en las credenciales.';
      toast.error(errMsg);
    } finally {
      setLoading(false);
    }
  };

  const handleRegister = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!name || !email || !password || !passwordConfirmation) {
      toast.error('Por favor, completa todos los campos.');
      return;
    }
    if (password !== passwordConfirmation) {
      toast.error('Las contraseñas no coinciden.');
      return;
    }
    setLoading(true);
    try {
      const res = await api.post(`/public/${tenantSlug}/auth/register`, {
        name,
        email,
        password,
        password_confirmation: passwordConfirmation,
      });
      setCustomerAuth(res.data.token, res.data.user);
      toast.success('Cuenta registrada correctamente.');
      fetchFavorites();
      fetchOrders();
    } catch (err: any) {
      const errMsg = err.response?.data?.message || err.response?.data?.errors?.email?.[0] || 'Error al registrarse.';
      toast.error(errMsg);
    } finally {
      setLoading(false);
    }
  };

  const handleLogout = async () => {
    setLoading(true);
    try {
      await api.post(`/public/${tenantSlug}/auth/logout`);
    } catch {
      // Ignore network errors on logout
    } finally {
      clearCustomerAuth();
      toast.success('Sesión cerrada.');
      setLoading(false);
      onClose();
    }
  };

  const handleToggleFavorite = async (pId: string) => {
    try {
      const res = await api.post(`/public/${tenantSlug}/favorites/${pId}`);
      toast.success(res.data.message);
      toggleFavoriteId(pId);
      fetchFavorites();
    } catch {
      toast.error('Error al actualizar favoritos');
    }
  };

  if (!isOpen) return null;

  return (
    <div style={{
      position: 'fixed',
      inset: 0,
      zIndex: 1000,
      background: 'rgba(0,0,0,0.65)',
      backdropFilter: 'blur(8px)',
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
      padding: '1rem'
    }}>
      <div className="glass-card animate-scale-in" style={{
        background: 'rgba(15, 23, 42, 0.95)',
        border: '1px solid var(--border)',
        width: '100%',
        maxWidth: isAuthenticated ? '750px' : '450px',
        maxHeight: '85vh',
        borderRadius: 'var(--radius-xl)',
        display: 'flex',
        flexDirection: 'column',
        boxShadow: '0 25px 50px -12px rgba(0, 0, 0, 0.5)',
        overflow: 'hidden'
      }}>
        {/* Modal Header */}
        <div style={{
          padding: '1.5rem',
          borderBottom: '1px solid var(--border)',
          display: 'flex',
          justifyContent: 'space-between',
          alignItems: 'center'
        }}>
          <h2 style={{
            margin: 0,
            fontSize: '1.25rem',
            fontFamily: 'var(--font-heading)',
            color: 'var(--text-primary)',
            display: 'flex',
            alignItems: 'center',
            gap: '0.5rem'
          }}>
            <User size={22} style={{ color: 'var(--primary)' }} />
            {isAuthenticated ? `Hola, ${user?.name}` : 'Mi Cuenta'}
          </h2>
          <button 
            onClick={onClose}
            style={{
              background: 'none',
              border: 'none',
              color: 'var(--text-muted)',
              cursor: 'pointer',
              display: 'flex',
              alignItems: 'center',
              padding: '0.25rem',
              borderRadius: '50%',
              transition: 'background-color 0.2s'
            }}
            onMouseOver={(e) => e.currentTarget.style.backgroundColor = 'rgba(255,255,255,0.05)'}
            onMouseOut={(e) => e.currentTarget.style.backgroundColor = 'none'}
          >
            <X size={20} />
          </button>
        </div>

        {/* Modal Content */}
        <div style={{ padding: '1.5rem', overflowY: 'auto', flex: 1 }}>
          {isAuthenticated ? (
            /* AUTHENTICATED PANEL */
            <div style={{ display: 'grid', gridTemplateColumns: '200px 1fr', gap: '1.5rem', height: '100%' }}>
              
              {/* Sidebar Menu */}
              <div style={{ display: 'flex', flexDirection: 'column', gap: '0.5rem', borderRight: '1px solid var(--border)', paddingRight: '1rem' }}>
                <button
                  onClick={() => setActiveTab('favorites')}
                  style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: '0.5rem',
                    padding: '0.75rem 1rem',
                    background: activeTab === 'favorites' ? 'rgba(var(--primary-rgb, 99, 102, 241), 0.15)' : 'none',
                    border: 'none',
                    borderRadius: 'var(--radius-md)',
                    color: activeTab === 'favorites' ? 'var(--primary)' : 'var(--text-muted)',
                    cursor: 'pointer',
                    textAlign: 'left',
                    fontWeight: activeTab === 'favorites' ? 'bold' : 'normal',
                    width: '100%'
                  }}
                >
                  <Heart size={16} /> Mis Favoritos
                </button>

                <button
                  onClick={() => setActiveTab('orders')}
                  style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: '0.5rem',
                    padding: '0.75rem 1rem',
                    background: activeTab === 'orders' ? 'rgba(var(--primary-rgb, 99, 102, 241), 0.15)' : 'none',
                    border: 'none',
                    borderRadius: 'var(--radius-md)',
                    color: activeTab === 'orders' ? 'var(--primary)' : 'var(--text-muted)',
                    cursor: 'pointer',
                    textAlign: 'left',
                    fontWeight: activeTab === 'orders' ? 'bold' : 'normal',
                    width: '100%'
                  }}
                >
                  <ShoppingBag size={16} /> Mis Pedidos
                </button>

                <div style={{ flex: 1 }} />

                <button
                  onClick={handleLogout}
                  disabled={loading}
                  style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: '0.5rem',
                    padding: '0.75rem 1rem',
                    background: 'rgba(239, 68, 68, 0.1)',
                    border: 'none',
                    borderRadius: 'var(--radius-md)',
                    color: '#ef4444',
                    cursor: 'pointer',
                    textAlign: 'left',
                    width: '100%'
                  }}
                >
                  <LogOut size={16} /> Cerrar Sesión
                </button>
              </div>

              {/* Sidebar Content */}
              <div>
                {activeTab === 'favorites' && (
                  <div>
                    <h3 style={{ margin: '0 0 1rem 0', fontSize: '1.1rem', color: 'var(--text-primary)' }}>Productos Guardados</h3>
                    {favorites.length === 0 ? (
                      <p style={{ color: 'var(--text-muted)', fontSize: '0.9rem' }}>No tienes productos en favoritos.</p>
                    ) : (
                      <div style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
                        {favorites.map((fav) => (
                          <div 
                            key={fav.id} 
                            style={{
                              display: 'flex',
                              alignItems: 'center',
                              justifyContent: 'space-between',
                              gap: '1rem',
                              padding: '0.75rem',
                              background: 'rgba(255,255,255,0.02)',
                              border: '1px solid var(--border)',
                              borderRadius: 'var(--radius-md)'
                            }}
                          >
                            <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem' }}>
                              <img 
                                src={fav.images?.[0]?.image_url || fav.image_url || 'https://via.placeholder.com/50'} 
                                alt={fav.name} 
                                style={{ width: '40px', height: '40px', objectFit: 'cover', borderRadius: 'var(--radius-sm)' }}
                              />
                              <div>
                                <h4 style={{ margin: 0, fontSize: '0.9rem', color: 'var(--text-primary)' }}>{fav.name}</h4>
                                <span style={{ fontSize: '0.8rem', color: 'var(--primary)', fontWeight: 'bold' }}>
                                  S/ {(fav.sale_price !== null ? fav.sale_price : fav.price)}
                                </span>
                              </div>
                            </div>
                            <button
                              onClick={() => handleToggleFavorite(fav.id)}
                              style={{
                                background: 'rgba(239, 68, 68, 0.1)',
                                border: 'none',
                                color: '#ef4444',
                                borderRadius: '50%',
                                width: '32px',
                                height: '32px',
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                cursor: 'pointer'
                              }}
                            >
                              <Heart size={14} fill="#ef4444" />
                            </button>
                          </div>
                        ))}
                      </div>
                    )}
                  </div>
                )}

                {activeTab === 'orders' && (
                  <div>
                    <h3 style={{ margin: '0 0 1rem 0', fontSize: '1.1rem', color: 'var(--text-primary)' }}>Historial de Pedidos</h3>
                    {orders.length === 0 ? (
                      <p style={{ color: 'var(--text-muted)', fontSize: '0.9rem' }}>Aún no has realizado pedidos.</p>
                    ) : (
                      <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem', maxHeight: '55vh', overflowY: 'auto' }}>
                        {orders.map((ord) => (
                          <div 
                            key={ord.id} 
                            style={{
                              padding: '1rem',
                              background: 'rgba(255,255,255,0.01)',
                              border: '1px solid var(--border)',
                              borderRadius: 'var(--radius-lg)',
                              display: 'flex',
                              flexDirection: 'column',
                              gap: '0.75rem'
                            }}
                          >
                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                              <div>
                                <span style={{ fontSize: '0.8rem', color: 'var(--text-muted)' }}>Pedido #{ord.id.substring(0, 8)}</span>
                                <div style={{ fontSize: '0.75rem', color: 'var(--text-muted)' }}>
                                  {new Date(ord.created_at!).toLocaleDateString()}
                                </div>
                              </div>
                              <span style={{
                                padding: '0.25rem 0.5rem',
                                borderRadius: 'var(--radius-sm)',
                                fontSize: '0.75rem',
                                fontWeight: 'bold',
                                textTransform: 'uppercase',
                                background: 
                                  ord.status === 'attended' ? 'rgba(34, 197, 94, 0.15)' :
                                  ord.status === 'cancelled' ? 'rgba(239, 68, 68, 0.15)' :
                                  'rgba(234, 179, 8, 0.15)',
                                color:
                                  ord.status === 'attended' ? '#22c55e' :
                                  ord.status === 'cancelled' ? '#ef4444' :
                                  '#eab308'
                              }}>
                                {ord.status === 'attended' ? 'Atendido' : ord.status === 'cancelled' ? 'Cancelado' : 'Pendiente'}
                              </span>
                            </div>

                            {/* Order Items */}
                            <div style={{ display: 'flex', flexDirection: 'column', gap: '0.25rem', borderTop: '1px solid rgba(255,255,255,0.05)', paddingTop: '0.5rem' }}>
                              {ord.items?.map((item: any) => (
                                <div key={item.id} style={{ display: 'flex', justifyContent: 'space-between', fontSize: '0.8rem', color: 'var(--text-muted)' }}>
                                  <span>{item.quantity}x {item.product_name}</span>
                                  <span>S/ {item.subtotal}</span>
                                </div>
                              ))}
                            </div>

                            <div style={{ display: 'flex', justifyContent: 'space-between', borderTop: '1px solid rgba(255,255,255,0.05)', paddingTop: '0.5rem', fontWeight: 'bold', fontSize: '0.9rem', color: 'var(--text-primary)' }}>
                              <span>Total</span>
                              <span>S/ {ord.total}</span>
                            </div>
                          </div>
                        ))}
                      </div>
                    )}
                  </div>
                )}
              </div>
            </div>
          ) : (
            /* GUEST: LOGIN / REGISTER FORMS */
            <div>
              {/* Form Navigation Tabs */}
              <div style={{ display: 'flex', borderBottom: '1px solid var(--border)', marginBottom: '1.5rem' }}>
                <button
                  onClick={() => setActiveTab('login')}
                  style={{
                    flex: 1,
                    padding: '0.75rem',
                    background: 'none',
                    border: 'none',
                    borderBottom: activeTab === 'login' ? '2px solid var(--primary)' : 'none',
                    color: activeTab === 'login' ? 'var(--text-primary)' : 'var(--text-muted)',
                    cursor: 'pointer',
                    fontWeight: activeTab === 'login' ? 'bold' : 'normal'
                  }}
                >
                  Iniciar Sesión
                </button>
                <button
                  onClick={() => setActiveTab('register')}
                  style={{
                    flex: 1,
                    padding: '0.75rem',
                    background: 'none',
                    border: 'none',
                    borderBottom: activeTab === 'register' ? '2px solid var(--primary)' : 'none',
                    color: activeTab === 'register' ? 'var(--text-primary)' : 'var(--text-muted)',
                    cursor: 'pointer',
                    fontWeight: activeTab === 'register' ? 'bold' : 'normal'
                  }}
                >
                  Registrarse
                </button>
              </div>

              {activeTab === 'login' ? (
                /* LOGIN FORM */
                <form onSubmit={handleLogin} style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
                  <div style={{ display: 'flex', flexDirection: 'column', gap: '0.25rem' }}>
                    <label style={{ fontSize: '0.85rem', color: 'var(--text-muted)' }}>Correo Electrónico</label>
                    <div style={{ position: 'relative' }}>
                      <Mail size={16} style={{ position: 'absolute', left: '0.75rem', top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
                      <input
                        type="email"
                        value={email}
                        onChange={(e) => setEmail(e.target.value)}
                        placeholder="ejemplo@correo.com"
                        style={{
                          width: '100%',
                          padding: '0.6rem 0.75rem 0.6rem 2.25rem',
                          background: 'rgba(0,0,0,0.2)',
                          border: '1px solid var(--border)',
                          borderRadius: 'var(--radius-md)',
                          color: '#fff',
                          fontSize: '0.9rem'
                        }}
                      />
                    </div>
                  </div>

                  <div style={{ display: 'flex', flexDirection: 'column', gap: '0.25rem' }}>
                    <label style={{ fontSize: '0.85rem', color: 'var(--text-muted)' }}>Contraseña</label>
                    <div style={{ position: 'relative' }}>
                      <Lock size={16} style={{ position: 'absolute', left: '0.75rem', top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
                      <input
                        type="password"
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                        placeholder="••••••••"
                        style={{
                          width: '100%',
                          padding: '0.6rem 0.75rem 0.6rem 2.25rem',
                          background: 'rgba(0,0,0,0.2)',
                          border: '1px solid var(--border)',
                          borderRadius: 'var(--radius-md)',
                          color: '#fff',
                          fontSize: '0.9rem'
                        }}
                      />
                    </div>
                  </div>

                  <button
                    type="submit"
                    disabled={loading}
                    className="btn-primary"
                    style={{
                      width: '100%',
                      padding: '0.75rem',
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'center',
                      gap: '0.5rem',
                      fontWeight: 'bold',
                      marginTop: '0.5rem'
                    }}
                  >
                    {loading ? <Loader2 size={18} className="animate-spin" /> : 'Entrar'}
                  </button>
                </form>
              ) : (
                /* REGISTER FORM */
                <form onSubmit={handleRegister} style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
                  <div style={{ display: 'flex', flexDirection: 'column', gap: '0.25rem' }}>
                    <label style={{ fontSize: '0.85rem', color: 'var(--text-muted)' }}>Nombre Completo</label>
                    <div style={{ position: 'relative' }}>
                      <User size={16} style={{ position: 'absolute', left: '0.75rem', top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
                      <input
                        type="text"
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        placeholder="Juan Pérez"
                        style={{
                          width: '100%',
                          padding: '0.6rem 0.75rem 0.6rem 2.25rem',
                          background: 'rgba(0,0,0,0.2)',
                          border: '1px solid var(--border)',
                          borderRadius: 'var(--radius-md)',
                          color: '#fff',
                          fontSize: '0.9rem'
                        }}
                      />
                    </div>
                  </div>

                  <div style={{ display: 'flex', flexDirection: 'column', gap: '0.25rem' }}>
                    <label style={{ fontSize: '0.85rem', color: 'var(--text-muted)' }}>Correo Electrónico</label>
                    <div style={{ position: 'relative' }}>
                      <Mail size={16} style={{ position: 'absolute', left: '0.75rem', top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
                      <input
                        type="email"
                        value={email}
                        onChange={(e) => setEmail(e.target.value)}
                        placeholder="ejemplo@correo.com"
                        style={{
                          width: '100%',
                          padding: '0.6rem 0.75rem 0.6rem 2.25rem',
                          background: 'rgba(0,0,0,0.2)',
                          border: '1px solid var(--border)',
                          borderRadius: 'var(--radius-md)',
                          color: '#fff',
                          fontSize: '0.9rem'
                        }}
                      />
                    </div>
                  </div>

                  <div style={{ display: 'flex', flexDirection: 'column', gap: '0.25rem' }}>
                    <label style={{ fontSize: '0.85rem', color: 'var(--text-muted)' }}>Contraseña</label>
                    <div style={{ position: 'relative' }}>
                      <Lock size={16} style={{ position: 'absolute', left: '0.75rem', top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
                      <input
                        type="password"
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                        placeholder="Mínimo 8 caracteres"
                        style={{
                          width: '100%',
                          padding: '0.6rem 0.75rem 0.6rem 2.25rem',
                          background: 'rgba(0,0,0,0.2)',
                          border: '1px solid var(--border)',
                          borderRadius: 'var(--radius-md)',
                          color: '#fff',
                          fontSize: '0.9rem'
                        }}
                      />
                    </div>
                  </div>

                  <div style={{ display: 'flex', flexDirection: 'column', gap: '0.25rem' }}>
                    <label style={{ fontSize: '0.85rem', color: 'var(--text-muted)' }}>Confirmar Contraseña</label>
                    <div style={{ position: 'relative' }}>
                      <Lock size={16} style={{ position: 'absolute', left: '0.75rem', top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
                      <input
                        type="password"
                        value={passwordConfirmation}
                        onChange={(e) => setPasswordConfirmation(e.target.value)}
                        placeholder="••••••••"
                        style={{
                          width: '100%',
                          padding: '0.6rem 0.75rem 0.6rem 2.25rem',
                          background: 'rgba(0,0,0,0.2)',
                          border: '1px solid var(--border)',
                          borderRadius: 'var(--radius-md)',
                          color: '#fff',
                          fontSize: '0.9rem'
                        }}
                      />
                    </div>
                  </div>

                  <button
                    type="submit"
                    disabled={loading}
                    className="btn-primary"
                    style={{
                      width: '100%',
                      padding: '0.75rem',
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'center',
                      gap: '0.5rem',
                      fontWeight: 'bold',
                      marginTop: '0.5rem'
                    }}
                  >
                    {loading ? <Loader2 size={18} className="animate-spin" /> : <><UserPlus size={16} /> Crear Cuenta</>}
                  </button>
                </form>
              )}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
