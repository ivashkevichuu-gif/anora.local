import { api } from '../api/client'

export const accountService = {
  deposit:         (amount)              => api.deposit({ amount }),
  withdraw:        (amount, bankDetails) => api.withdraw({ amount, bank_details: bankDetails }),
  saveBank:        (bankDetails)         => api.saveBank({ bank_details: bankDetails }),
  getTransactions: ()                    => api.transactions(),
}
